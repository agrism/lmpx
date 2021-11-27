<?php
//abstract base class for in-memory representation of various business entities.  The only item
//we have implemented at this point is InventoryItem (see below).
abstract class Entity
{
    static protected $_defaultEntityManager = null;


    protected $_data = null;

    protected $_em = null;
    protected $_entityName = null;
    protected $_id = null;

    public function init() {}

    abstract public function getMembers();

    abstract public function getPrimary();

    //setter for properies and items in the underlying data array
    public function __set($variableName, $value)
    {
        if (array_key_exists($variableName, array_change_key_case($this->getMembers()))) {
            $newData = $this->_data;
            $newData[$variableName] = $value;
            $this->_update($newData);
            $this->_data = $newData;
        } else {
            if (property_exists($this, $variableName)) {
                $this->$variableName = $value;
            } else {
                throw new Exception("Set failed. Class " . get_class($this) .
                                    " does not have a member named " . $variableName . ".");
            }
        }
    }

    //getter for properies and items in the underlying data array
    public function __get($variableName)
    {
        if (array_key_exists($variableName, array_change_key_case($this->getMembers()))) {
            $data = $this->read();
            return $data[$variableName];
        } else {
            if (property_exists($this, $variableName)) {
                return $this->$variableName;
            } else {
                throw new Exception("Get failed. Class " . get_class($this) .
                                    " does not have a member named " . $variableName . ".");
            }
        }
    }

    static public function setDefaultEntityManager($em)
    {
        self::$_defaultEntityManager = $em;
    }

    //Factory function for making entities.
    static public function getEntity($entityName, $data, $entityManager = null)
    {
        $em = $entityManager === null ? self::$_defaultEntityManager : $entityManager;
        $entity = $em->create($entityName, $data);
        $entity->init();
        return $entity;
    }

    static public function getDefaultEntityManager()
    {
        return self::$_defaultEntityManager;
    }

    public function create($entityName, $data)
    {
        $entity = self::getEntity($entityName, $data);
        return $entity;
    }

    public function read()
    {
        return $this->_data;
    }

    public function update($newData)
    {
        $this->_em->update($this, $newData);
        $this->_data = $newData;
    }

    public function delete()
    {
        $this->_em->delete($this);
    }
}

//Helper function for printing out error information
function getLastError()
{
    $errorInfo = error_get_last();
    $errorString = " Error type {$errorInfo['type']}, {$errorInfo['message']} on line {$errorInfo['line']} of " .
        "{$errorInfo['file']}. ";
    return $errorString;
}

//A super-simple replacement class for a real database, just so we have a place for storing results.
class DataStore
{
    protected $_storePath = null;

    protected $_dataStore = array();

    public function __construct($storePath)
    {
        $this->_storePath = $storePath;
        if (!file_exists($storePath)) {
            if (!touch($storePath)) {
                throw new Exception("Could not create data store file $storePath. Details:" . getLastError());
            }
            if (!chmod($storePath, 0777)) {
                throw new Exception("Could not set read/write on data store file $storePath. " .
                                    "Details:" . getLastError());
            }
        }
        if (!is_readable($storePath) || !is_writable($storePath)) {
            throw new Exception("Data store file $storePath must be readable/writable. Details:" . getlastError());
        }
        $rawData = file_get_contents($storePath);

        if ($rawData === false) {
            throw new Exception("Read of data store file $storePath failed.  Details:" . getLastError());
        }
        if (strlen($rawData > 0)) {
            $this->_dataStore = unserialize($rawData);
        } else {
            $this->_dataStore = null;
        }
    }

    //update the store with information
    public function set($item, $primary, $data)
    {
        $foundItem = null;
        $this->_dataStore[$item][$primary] = $data;
    }

    //get information
    public function get($item, $primary)
    {
        if (isset($this->_dataStore[$item][$primary])) {
            return $this->_dataStore[$item][$primary];
        } else {
            return null;
        }
    }

    //delete an item.
    public function delete($item, $primary)
    {
        if (isset($this->_dataStore[$item][$primary])) {
            unset($this->_dataStore[$item][$primary]);
        }
    }

    //save everything
    public function save()
    {
        $result = file_put_contents($this->_storePath, serialize($this->_dataStore));
        if ($result === null) {
            throw new Exception("Write of data store file $this->_storePath failed.  Details:" . getLastError());
        }
    }

    //Which types of items do we have stored
    public function getItemTypes()
    {
        if (is_null($this->_dataStore)) {
            return array();
        }
        return array_keys($this->_dataStore);
    }

    //get keys for an item-type, so we can loop over.
    public function getItemKeys($itemType)
    {
        return array_keys($this->_dataStore[$itemType]);
    }
}

//This class managed in-memory entities and commmunicates with the storage class (DataStore in our case).
class EntityManager
{

    protected $_entities = array();

    protected $_entityIdToPrimary = array();

    protected $_entityPrimaryToId = array();

    protected $_entitySaveList = array();

    protected $_nextId = null;

    protected $_dataStore = null;

    public function __construct($storePath)
    {
        $this->_dataStore = new DataStore($storePath);

        $this->_nextId = 1;

        $itemTypes = $this->_dataStore->getItemTypes();
        foreach ($itemTypes as $itemType)
        {
            $itemKeys = $this->_dataStore->getItemKeys();
            foreach ($itemKeys as $itemKey) {
                $entity = $this->create($itemType, $this->_dataStore->get($itemType, $itemKey), true);
            }
        }
    }

    //create an entity
    public function create($entityName, $data, $fromStore = false)
    {
        $entity = new $entityName;
        $entity->_entityName = $entityName;
        $entity->_data = $data;
        $entity->_em = Entity::getDefaultEntityManager();
        $id = $entity->_id = $this->_nextId++;
        $this->_entities[$id] = $entity;
        $primary = $data[$entity->getPrimary()];
        $this->_entityIdToPrimary[$id] = $primary;
        $this->_entityPrimaryToId[$primary] = $id;
        if ($fromStore !== true) {
            $this->_entitySaveList[] = $id;
        }

        return $entity;
    }

    //update
    public function update($entity, $newData)
    {
        if ($newData === $entity->_data) {
            //Nothing to do
            return $entity;
        }

        $this->_entitySaveList[] = $entity->_id;
        $oldPrimary = $entity->{$entity->getPrimary()};
        $newPrimary = $newData[$entity->getPrimary()];
        if ($oldPrimary != $newPrimary)
        {
            $this->_dataStore->delete(get_class($entity),$oldPrimary);
            unset($this->_entityPrimaryToId[$oldPrimary]);
            $this->_entityIdToPrimary[$entity->$id] = $newPrimary;
            $this->_entityPrimaryToId[$newPrimary] = $entity->$id;
        }
        $entity->_data = $newData;

        return $entity;
    }

    //Delete
    public function delete($entity)
    {
        $id = $entity->_id;
        $entity->_id = null;
        $entity->_data = null;
        $entity->_em = null;
        $this->_entities[$id] = null;
        $primary = $entity->{$entity->getPrimary()};
        $this->_dataStore->delete(get_class($entity),$primary);
        unset($this->_entityIdToPrimary[$id]);
        unset($this->_entityPrimaryToId[$primary]);
        return null;
    }

    public function findByPrimary($entity, $primary)
    {
        if (isset($this->_entityPrimaryToId[$primary])) {
            $id = $this->_entityPrimaryToId[$primary];
            return $this->_entities[$id];
        } else {
            return null;
        }
    }

    //Update the datastore to update itself and save.
    public function updateStore() {
        foreach($this->_entitySaveList as $id) {
            $entity = $this->_entities[$id];
            $this->_dataStore->set(get_class($entity),$entity->{$entity->getPrimary()},$entity->_data);
        }
        $this->_dataStore->save();
    }
}

//An example entity, which some business logic.  we can tell inventory items that they have shipped or been received
//in
class InventoryItem extends Entity
{
    //Update the number of items, because we have shipped some.
    public function itemsHaveShipped($numberShipped)
    {
        $current = $this->qoh;
        $current -= $numberShipped;
        $newData = $this->_data;
        $newData['qoh'] = $current;
        $this->update($newData);

    }

    //We received new items, update the count.
    public function itemsReceived($numberReceived)
    {

        $newData = $this->_data;
        $current = $this->qoh;

        for($i = 1; $i <= $numberReceived; $i++) {
            //notifyWareHouse();  //Not implemented yet.
            $newData['qoh'] = $current++;
        }
        $this->update($newData);
    }

    public function changeSalePrice($salePrice)
    {
        $newData = $this->_data;
        $newData['salePrice'] = $this->update($newData);
    }

    public function getMembers()
    {
        //These are the field in the underlying data array
        return array("sku" => 1, "qoh" => 1, "cost" => 1, "salePrice" => 1)    ;
    }

    public function getPrimary()
    {
        //Which field constitutes the primary key in the storage class?
        return "sku";
    }
}

function driver()
{
    $dataStorePath = "data_store_file.data";
    $entityManager = new EntityManager($dataStorePath);
    Entity::setDefaultEntityManager($entityManager);
    //create five new Inventory items

    $item1 = Entity::getEntity('InventoryItem',
                               array('sku' => 'abc-4589', 'qoh' => 0, 'cost' => '5.67', 'salePrice' => '7.27'));
    $item2 = Entity::getEntity('InventoryItem',
                               array('sku' => 'hjg-3821', 'qoh' => 0, 'cost' => '7.89', 'salePrice' => '12.00'));
    $item3 = Entity::getEntity('InventoryItem',
                               array('sku' => 'xrf-3827', 'qoh' => 0, 'cost' => '15.27', 'salePrice' => '19.99'));
    $item4 = Entity::getEntity('InventoryItem',
                               array('sku' => 'eer-4521', 'qoh' => 0, 'cost' => '8.45', 'salePrice' => '1.03'));
    $item5 = Entity::getEntity('InventoryItem',
                               array('sku' => 'qws-6783', 'qoh' => 0, 'cost' => '3.00', 'salePrice' => '4.97'));

    $item1->itemsReceived(4);
    $item2->itemsReceived(2);
    $item3->itemsReceived(12);
    $item4->itemsReceived(20);
    $item5->itemsReceived(1);

    $item3->itemsHaveShipped(5);
    $item4->itemsHaveShipped(16);

    $item4->changeSalePrice(0.87);

    $entityManager->updateStore();
}

driver();
?>
