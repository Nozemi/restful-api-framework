<?php namespace NozCore;

use ClanCats\Hydrahon\Builder;
use ClanCats\Hydrahon\Query\Sql\Select;
use ClanCats\Hydrahon\Query\Sql\Table;
use JsonSerializable;
use NozCore\Message\AccessDenied;
use NozCore\Objects\Users\Group;
use NozCore\Objects\Users\User;

abstract class ObjectBase implements JsonSerializable {

    /** @var Builder $db */
    protected $db = null;
    /** @var \PDO $pdo */
    protected $pdo = null;

    protected $table = '';
    protected $defaultSort = 'id';
    protected $defaultSortOrder = 'asc';

    protected $hooks = [];

    /** @var Table $dbTable */
    protected $dbTable = null;

    protected $permissions = [];

    protected $queryLimit = 25;
    protected $queryPage = 1;

    protected $betweenColumn = null;

    protected $selfContentCheck = null;
    protected $protectedProperties = [];

    protected $otherData = [];
    protected $hideFromGET = [];

    /**
     * Define the table structure in an array with key being column name and value being data type.
     *
     * @return array
     */
    abstract public function data();

    /**
     * ObjectBase constructor.
     *
     * @param array $data
     */
    public function __construct($data = []) {
        $this->db = $GLOBALS['hydra'];
        $this->pdo = $GLOBALS['pdo'];

        $this->dbTable = $this->db->table($this->table);

        foreach ($this->data() as $property => $type) {
            if (isset($data[$property])) {
                $this->$property = DataTypes::parseValue($data[$property], $type);
                unset($data[$property]);
            }
        }

        $this->otherData = $data;
    }

    /**
     * Custom serializer for objects.
     *
     * @return array
     * @throws \ClanCats\Hydrahon\Query\Sql\Exception
     * @throws \ReflectionException
     */
    public function jsonSerialize() {
        $this->permissions();

        $dataToSerialize = [];
        foreach ($this->data() as $property => $type) {
            if ($this->getPermission($property)) {
                if (isset($this->$property) && !in_array($property, $this->hideFromGET)) {
                    $dataToSerialize[$property] = $this->$property;
                }
            }
        }

        return $dataToSerialize;
    }

    /**
     * Get all entries from the selected database
     *
     * @return array
     * @throws \ReflectionException
     */
    public function getAll() {
        $objects = [];

        /** @var Table $table */
        $query = $this->dbTable->select()
            ->orderBy([$this->defaultSort], $this->defaultSortOrder)
            ->limit($this->queryLimit)
            ->offset($this->queryPage)
            ->execute();

        foreach ($query as $row) {
            $object = new $this($row);
            $this->callHooks('SUCCESSFUL_GET_EVENT', $object);
            $objects[] = $object;
        }

        return $objects;
    }

    /**
     * @param        $name
     * @param string $column
     *
     * @return array
     * @throws \ClanCats\Hydrahon\Query\Sql\Exception
     * @throws \ReflectionException
     */
    public function getByName($name, $column = 'name') {
        $objects = [];

        $this->callHooks('BEFORE_GET_EVENT');
        $this->callHooks('BEFORE_GET_BY_NAME_EVENT');

        /*print_r(json_encode([
            'limit' => $this->queryLimit,
            'page'  => $this->queryPage
        ])); exit;*/

        $query = $this->dbTable->select()
            ->orderBy([$this->defaultSort], $this->defaultSortOrder)
            ->where($column, 'LIKE', '%' . $name . '%')
            ->limit($this->getQueryLimit())
            ->offset($this->queryPage)
            ->execute();

        foreach ($query as $row) {
            $object = new $this($row);
            $this->callHooks('SUCCESSFUL_GET_EVENT', $object);
            $this->callHooks('SUCCESSFUL_GET_BY_NAME_EVENT', $object);
            $objects[] = $object;
        }

        return $objects;
    }

    /**
     * @param array $filters
     *
     * @return array
     * @throws \ReflectionException
     * @throws \ClanCats\Hydrahon\Query\Sql\Exception
     */
    public function getByFilters(array $filters) {
        $objects = [];

        $this->callHooks('BEFORE_GET_EVENT');
        $this->callHooks('BEFORE_GET_BY_FILTERS_EVENT');

        $query = $this->dbTable->select()
            ->orderBy([$this->defaultSort], $this->defaultSortOrder);

        foreach ($filters as $filter => $value) {
            $query->where($filter, 'like', '%' . $value . '%');
        }

        $query = $query
            ->orderBy([$this->defaultSort], $this->defaultSortOrder)
            ->limit($this->queryLimit)
            ->page($this->queryPage)
            ->execute();

        foreach ($query as $row) {
            $object = new $this($row);
            $this->callHooks('SUCCESSFUL_GET_EVENT', $object);
            $this->callHooks('SUCCESSFUL_GET_BY_FILTERS_EVENT', $object);
            $objects[] = $object;
        }

        return $objects;
    }

    /**
     * @param $since
     * @param $until
     *
     * @return array
     * @throws \ClanCats\Hydrahon\Query\Sql\Exception
     * @throws \ReflectionException
     */
    public function getBetween($since, $until) {
        $since = date('Y-m-d H:i:s', strtotime($since));
        $until = date('Y-m-d H:i:s', strtotime($until));

        $objects = [];

        $this->callHooks('BEFORE_GET_EVENT');
        $this->callHooks('BEFORE_GET_BETWEEN_EVENT');

        $query = $this->dbTable->select()
            ->orderBy([$this->defaultSort], $this->defaultSortOrder);

        if ($this->betweenColumn != null) {
            $column = $this->betweenColumn;
            $query->where(function ($q) use ($since, $until, $column) {
                /** @var Select $q */
                if ($until != null) {
                    $q->where($column, '<=', $until);
                }

                $q->where($column, '>=', $since);
            });

            $query = $query
                ->limit($this->queryLimit)
                ->page($this->queryPage)
                ->execute();

            foreach ($query as $row) {
                $object = new $this($row);
                $this->callHooks('SUCCESSFUL_GET_EVENT', $object);
                $this->callHooks('SUCCESSFUL_GET_BETWEEN_EVENT', $object);
                $objects[] = $object;
            }
        }

        return $objects;
    }

    /**
     * @param $id
     *
     * @return ObjectBase
     * @throws \ClanCats\Hydrahon\Query\Sql\Exception
     * @throws \ReflectionException
     */
    public function get($id) {
        $this->callHooks('BEFORE_GET_EVENT');

        $result = $this->dbTable->select()
            ->orderBy([$this->defaultSort], $this->defaultSortOrder)
            ->where('id', $id)
            ->one();

        if (!empty($result)) {
            $object = new $this($result);
            $this->callHooks('SUCCESSFUL_GET_EVENT', $object);
            return $object;
        }

        return null;
    }

    /**
     * @param string $method
     *
     * @return ObjectBase
     * @throws \ClanCats\Hydrahon\Query\Sql\Exception
     * @throws \ReflectionException
     */
    public function save($method = 'POST') {
        $this->callHooks('BEFORE_SAVE_EVENT');

        if ($this->getProperty('id')) {
            $this->callHooks('BEFORE_SAVE_WITH_ID_EVENT');
        } else {
            $this->callHooks('BEFORE_SAVE_WITHOUT_ID_EVENT');
        }

        $this->permissions($method, true);

        $dataToSerialize = [];
        foreach ($this->data() as $property => $type) {
            if (isset($this->$property) && $this->getPermission($property, true)) {
                $dataToSerialize[$property] = $this->$property;
            }
        }

        if ($this->getProperty('id') && $this->get($this->getProperty('id')) != null) {
            $selfContentCheck = $this->selfContentCheck;
            if (!isset($_SESSION['user']['id']) || $this->$selfContentCheck != $_SESSION['user']['id']) {
                new AccessDenied('You don\'t have permission to update this object.');
            }

            // Update object
            $this->callHooks('BEFORE_SAVE_EXISTING_EVENT');
            $this->dbTable->update($dataToSerialize)
                ->where('id', $this->getProperty('id'))
                ->execute();
            $objectId = $this->getProperty('id');
            $this->callHooks('AFTER_SAVE_EXISTING_EVENT');
        } else {
            // Create object
            $this->callHooks('BEFORE_SAVE_NEW_EVENT');
            $this->dbTable->insert($dataToSerialize)->execute();

            $objectId = $this->pdo->lastInsertId();
            $this->callHooks('AFTER_SAVE_NEW_EVENT', $this->get($objectId));
        }

        return $this->get($objectId);
    }

    /**
     * @param $id
     *
     * @throws \ClanCats\Hydrahon\Query\Sql\Exception
     */
    public function delete($id) {
        $this->dbTable->delete()->where('id', $id)->execute();
    }

    /**
     * Get a property from the object.
     *
     * @param $property
     *
     * @return bool|mixed|null
     */
    public function getProperty($property) {
        if (array_key_exists($property, $this->data()) && isset($this->$property)) {
            return DataTypes::parseValue($this->$property, $this->data()[$property]);
        }

        return false;
    }

    /**
     * Set a property for the object.
     * If it succeeds, it will return the value you set. Otherwise it will return false.
     *
     * @param $property
     * @param $value
     *
     * @return bool
     */
    public function setProperty($property, $value) {
        if (array_key_exists($property, $this->data())) {
            $this->$property = DataTypes::parseValue($value, $this->data()[$property]);
            return $this->$property;
        }

        return false;
    }

    /**
     * @param      $hook
     * @param null $object
     *
     * @throws \ReflectionException
     */
    public function callHooks($hook, $object = null) {
        if (isset($this->hooks[$hook])) {
            foreach ($this->hooks[$hook] as $methodName) {
                if (method_exists($this, $methodName)) {
                    $method = new \ReflectionMethod($this, $methodName);
                    if ($method->getNumberOfRequiredParameters() == 1) {
                        $this->$methodName($object);
                    } else {
                        $this->$methodName();
                    }
                }
            }
        }
    }

    /**
     * @param string $method
     * @param bool   $checkProtected
     *
     * @throws \ClanCats\Hydrahon\Query\Sql\Exception
     */
    public function permissions($method = 'GET', $checkProtected = false) {
        foreach ($this->data() as $property => $type) {
            $this->permissions[$property] = false;
        }

        if ($method == 'SERVER') {
            foreach ($this->data() as $property => $type) {
                $this->permissions[$property] = true;
            }
        } else {
            /** @var Table $table */
            $table = $this->db->table('api_permission');

            $groups = [0];
            if (isset($_SESSION['user'])) {
                $groupId = $_SESSION['user']['groupId'];
                $groups[] = $groupId;

                // TODO: Add a function to get inheritance from the player group
            }

            $result = $table->select()
                ->where('groupId', 'in', $groups)
                ->andWhere('table', $this->table)
                ->andWhere('method', $method)
                ->execute();

            foreach ($result as $item) {
                $this->permissions[$item['key']] = boolval($item['value']);
            }

            if($checkProtected) {
                foreach ($this->protectedProperties as $protected) {
                    $this->permissions[$protected] = false;
                }
            }
        }
    }

    /**
     * @param      $key
     * @param bool $checkProtected
     *
     * @return bool|mixed
     * @throws \ClanCats\Hydrahon\Query\Sql\Exception
     * @throws \ReflectionException
     */
    public function getPermission($key, $checkProtected = false) {
        if (isset($_SESSION['user']['id'])) {
            $user = new User();
            $user = $user->get($_SESSION['user']['id']);

            $group = new Group();
            $group = $group->get($user->getProperty('groupId'));

            if ($group->getProperty('admin')) {
                return true;
            }
        }

        if ($this->selfContentCheck != null && isset($_SESSION['user'])) {
            if ($checkProtected && in_array($key, $this->protectedProperties)) {
                return false;
            } else {
                $property = $this->selfContentCheck;
                if ($this->$property == $_SESSION['user']['id']) {
                    return true;
                }
            }
        }

        if (isset($this->permissions[$key])) {
            return $this->permissions[$key];
        }

        return false;
    }

    public function setQueryLimit($limit) {
        $this->queryLimit = $limit;
    }

    public function setQueryPage($page) {
        $rawPage = ((intval($page) - 1) * $this->queryLimit);
        $this->queryPage = ($rawPage <= 0) ? 0 : $rawPage;
    }

    public function getQueryLimit() {
        return $this->queryLimit;
    }
}