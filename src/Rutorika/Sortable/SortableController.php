<?php

namespace Rutorika\Sortable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class SortableController extends Controller
{
    /**
     * @param Request $request
     *
     * @return array
     */
    public function sort(Request $request)
    {
        $bag= (object) array();
        $session=new Session();
        $sortableEntities = app('config')->get('sortable.entities', []);
        $validator = $this->getValidator($sortableEntities, $request);
        if (!$validator->passes()) {
            return [
                'success' => false,
                'errors' => $validator->errors(),
                'failed' => $validator->failed(),
            ];
        }

        /** @var Model|bool $entityClass */
        list($entityClass, $relation) = $this->getEntityInfo($sortableEntities, (string) $request->input('entityName'));
        $method = ($request->input('type') === 'moveAfter') ? 'moveAfter' : 'moveBefore';

        if (!$relation) {
            /** @var SortableTrait $entity */
            $entity = $entityClass::find($request->input('id'));
            $postionEntity = $entityClass::find($request->input('positionEntityId'));
            $entity->$method($postionEntity);
        } else {
            $parentEntity = $entityClass::find($request->input('parentId'));
            $entity = $parentEntity->$relation()->find($request->input('id'));
            $postionEntity = $parentEntity->$relation()->find($request->input('positionEntityId'));
            $parentEntity->$relation()->$method($entity, $postionEntity);
        }

        if($request->input('entityName')==='grouped_articles'){
            $bag->value_change='casinos_list_properties';
        }
        if($request->input('entityName')==='grouped_articles1'){
            $bag->value_change='slot_game_casino_order_list';
        }
        if($request->input('entityName')==='games1'){
            $bag->value_change='slot_game_details';
        }

        if($request->input('entityName')==='games1'){
            $bag->value_change='slot_game_details';
        }
        if($request->input('entityName')==='list_of_casinos_on_slots'){
            $bag->value_change='slot_game_casino_order_list';
        }

        $bag->domain=$session->get('domain');
        $bag->action='position_change';
        //$bag->request=$request;
        $bag->value_id=$request->input('id');
        $this->get_update($bag);

        return ['success' => true];
    }

    public function get_update($bag){

        DB::table('12_refactored.redis_updates')->insert(['domain_name' => $bag->domain,
            'link' => $bag->link ?? null,
            'value_change' => $bag->value_change ?? null,
            'user_id' => null,
            'value_id' => $bag->value_id ?? null,
            'page_id' => $bag->page_id ?? null,
            'action' => $bag->action ?? null,
            'request' => $bag->request ?? null,
            'variation' => $bag->variation ?? null,]);
        // dd($bag);
        // dd($bag);
        $message=json_encode($bag).'Your delivery sir!';
        Redis::publish('pand0ra', json_encode($bag));
        //test
    }

    /**
     * @param array   $sortableEntities
     * @param Request $request
     *
     * @return \Illuminate\Validation\Validator
     */
    protected function getValidator($sortableEntities, $request)
    {
        /** @var \Illuminate\Validation\Factory $validator */
        $validator = app('validator');

        $rules = [
            'type' => ['required', 'in:moveAfter,moveBefore'],
            'entityName' => ['required', 'in:' . implode(',', array_keys($sortableEntities))],
            'id' => 'required',
            'positionEntityId' => 'required',
        ];

        /** @var Model|bool $entityClass */
        list($entityClass, $relation) = $this->getEntityInfo($sortableEntities, (string) $request->input('entityName'));

        if (!class_exists($entityClass)) {
            $rules['entityClass'] = 'required'; // fake rule for not exist field
            return $validator->make($request->all(), $rules);
        }

        $connectionName = with(new $entityClass())->getConnectionName();
        $tableName = with(new $entityClass())->getTable();
        $primaryKey = with(new $entityClass())->getKeyName();

        if (!empty($connectionName)) {
            $tableName = $connectionName . '.' . $tableName;
        }

        if (!$relation) {
            $rules['id'] .= '|exists:' . $tableName . ',' . $primaryKey;
            $rules['positionEntityId'] .= '|exists:' . $tableName . ',' . $primaryKey;
        } else {
            /** @var BelongsToSortedMany $relationObject */
            $relationObject = with(new $entityClass())->$relation();
            $pivotTable = $relationObject->getTable();

            $rules['parentId'] = 'required|exists:' . $tableName . ',' . $primaryKey;
            $rules['id'] .= '|exists:' . $pivotTable . ',' . $relationObject->getRelatedKey() . ',' . $relationObject->getForeignKey() . ',' . $request->input('parentId');
            $rules['positionEntityId'] .= '|exists:' . $pivotTable . ',' . $relationObject->getRelatedKey() . ',' . $relationObject->getForeignKey() . ',' . $request->input('parentId');
        }

        return $validator->make($request->all(), $rules);
    }

    /**
     * @param array  $sortableEntities
     * @param string $entityName
     *
     * @return array
     */
    protected function getEntityInfo($sortableEntities, $entityName)
    {
        $relation = false;

        $entityConfig = $entityName ? array_get($sortableEntities, $entityName, false) : false;

        if (is_array($entityConfig)) {
            $entityClass = $entityConfig['entity'];
            $relation = !empty($entityConfig['relation']) ? $entityConfig['relation'] : false;
        } else {
            $entityClass = $entityConfig;
        }

        return [$entityClass, $relation];
    }
}
