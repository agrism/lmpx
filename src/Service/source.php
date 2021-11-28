<?php
//abstract base class for in-memory representation of various business entities.  The only item
//we have implemented at this point is InventoryItem (see below).
namespace App\Service;

use RuntimeException;
use SplObserver;
use SplSubject;

use function PHPUnit\Framework\assertEquals;

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

    //setter for properties and items in the underlying data array
    public function __set($variableName, $value)
    {
        if (
            array_key_exists
            (
                $variableName,
                array_change_key_case($this->getMembers())
            )
        ) {
            $newData = $this->_data;
            $newData[$variableName] = $value;
            $this->update($newData);
            $this->_data = $newData;
        } elseif (property_exists($this, $variableName)) {
            $this->$variableName = $value;
        } else {
            throw new RuntimeException("Set failed. Class " . get_class($this) .
                                " does not have a member named " . $variableName . ".");
        }
    }

    // added by EA extended, mandatory couple for __set() magic
    public function __isset($variableName)
    {
        //
    }

    //getter for properties and items in the underlying data array
    public function __get($variableName)
    {
        if (
            array_key_exists
            (
                $variableName,
                array_change_key_case($this->getMembers())
            )
        ) {
            $data = $this->read();
            return $data[$variableName];
        }

        if (property_exists($this, $variableName)) {
            return $this->$variableName;
        }

        throw new RuntimeException("Get failed. Class " . get_class($this) .
                            " does not have a member named " . $variableName . ".");
    }

    public static function setDefaultEntityManager($em): void
    {
        self::$_defaultEntityManager = $em;
    }

    //Factory function for making entities.
    public static function getEntity($entityName, $data, $entityManager = null)
    {
        $em = $entityManager === null ? self::$_defaultEntityManager : $entityManager;
        $entity = $em->create($entityName, $data);
        $entity->init();
        return $entity;
    }

    public static function getDefaultEntityManager()
    {
        return self::$_defaultEntityManager;
    }

    public function create($entityName, $data)
    {
        return self::getEntity($entityName, $data);
    }

    public function read()
    {
        return $this->_data;
    }

    public function update($newData): void
    {
        $this->_em->update($this, $newData);
        $this->_data = $newData;
    }

    public function delete(): void
    {
        $this->_em->delete($this);
    }
}

//Helper function for printing out error information
function getLastError(): string
{
    $errorInfo = error_get_last();
    return " Error type {$errorInfo['type']}, {$errorInfo['message']} on line {$errorInfo['line']} of " .
        "{$errorInfo['file']}. ";
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
                throw new RuntimeException("Could not create data store file $storePath. Details:" . getLastError());
            }
            if (!chmod($storePath, 0777)) {
                throw new RuntimeException("Could not set read/write on data store file $storePath. " .
                                    "Details:" . getLastError());
            }
        }
        if (!is_readable($storePath) || !is_writable($storePath)) {
            throw new RuntimeException("Data store file $storePath must be readable/writable. Details:" . getlastError());
        }
        $rawData = file_get_contents($storePath);

        if ($rawData === false) {
            throw new RuntimeException("Read of data store file $storePath failed.  Details:" . getLastError());
        }
        if ($rawData !== '') {
            $this->_dataStore = unserialize($rawData, ['allowed_classes' => true]);
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
        return $this->_dataStore[$item][$primary] ?? null;
    }

    //delete an item.
    public function delete($item, $primary): void
    {
        if (isset($this->_dataStore[$item][$primary])) {
            unset($this->_dataStore[$item][$primary]);
        }
    }

    //save everything
    public function save(): void
    {
        $result = file_put_contents($this->_storePath, serialize($this->_dataStore));
        if ($result === null) {
            throw new RuntimeException("Write of data store file $this->_storePath failed.  Details:" . getLastError());
        }
    }

    //Which types of items do we have stored
    public function getItemTypes(): array
    {
        if (is_null($this->_dataStore)) {
            return array();
        }
        return array_keys($this->_dataStore);
    }

    //get keys for an item-type, so we can loop over.
    public function getItemKeys($itemType): array
    {
        return array_keys($this->_dataStore[$itemType]);
    }
}

//This class managed in-memory entities and communicates with the storage class (DataStore in our case).
class EntityManager implements \SplSubject
{
    private const MAIL_MESSENGER = 'MAIL_MESSENGER';

    public array $updateResult = [];

    protected array $_entities = [];

    protected array $_entityIdToPrimary = [];

    protected array $_entityPrimaryToId = [];

    protected array $_entitySaveList = [];

    protected $_nextId = null;

    protected $_dataStore = null;

    private \SplObjectStorage $observers;
    private string $observerTarget = '';

    public function __construct($storePath)
    {
        $this->_dataStore = new DataStore($storePath);
        $this->_nextId = 1;
        $itemTypes = $this->_dataStore->getItemTypes();

        foreach ($itemTypes as $itemType)
        {
            $itemKeys = $this->_dataStore->getItemKeys($itemType);
            foreach ($itemKeys as $itemKey) {
                $this->create($itemType, $this->_dataStore->get($itemType, $itemKey), true);
            }
        }
        $this->observers = new \SplObjectStorage();
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
            // fixed in two lines below $entity->$id
            $this->_entityIdToPrimary[$entity->_id] = $newPrimary;
            $this->_entityPrimaryToId[$newPrimary] = $entity->_id;
        }

        if ($entity->_data['qoh'] >=5 && $newData['qoh'] < 5) {
            $this->observerTarget = self::MAIL_MESSENGER;
        }
        $entity->_data = $newData;
        $this->updateResult = $newData;
        $this->notify();

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
        unset($this->_entityIdToPrimary[$id], $this->_entityPrimaryToId[$primary]);
        return null;
    }

    public function findByPrimary($entity, $primary)
    {
        if (isset($this->_entityPrimaryToId[$primary])) {
            $id = $this->_entityPrimaryToId[$primary];
            return $this->_entities[$id];
        }

        return null;
    }

    //Update the datastore to update itself and save.
    public function updateStore(): void
    {
        foreach($this->_entitySaveList as $id) {
            $entity = $this->_entities[$id];
            $this->_dataStore->set(get_class($entity),$entity->{$entity->getPrimary()},$entity->_data);
        }
        $this->_dataStore->save();
    }

    public function attach(SplObserver $observer): void
    {
        $this->observers->attach($observer);
    }

    public function detach(SplObserver $observer): void
    {
        $this->observers->detach($observer);
    }

    public function notify(): void
    {
        foreach ($this->observers as $observer) {
            if ($this->observerTarget === self::MAIL_MESSENGER && $observer instanceof MailMessengerInterface) {
                $observer->update($this);
                $this->observerTarget = '';
            } elseif ($observer instanceof MailMessengerInterface) {
                continue;
            } else {
                $observer->update($this);
            }
        }
    }
}

// separate observers
interface FileLoggerInterface{}
class FileLogger implements \SplObserver, FileLoggerInterface
{

    private string $loggerPath;
    private SplSubject $subject;

    public function __construct(string $loggerPath) {
        $this->loggerPath = $loggerPath;

        if (!($this->loggerPath)) {
            throw new RuntimeException("Data store file $loggerPath cannot be null.");
        }

        if (file_exists($loggerPath) && !is_writable($this->loggerPath)) {
            throw new RuntimeException("Data store file $loggerPath must be writable.");
        }
    }

    public function update(SplSubject $subject): void
    {
        echo 'em::update::FileLogger' . "\n";

        $this->subject = $subject;
        $this->save();
    }


    private function save(): void
    {
        $result = file_put_contents
        (
            $this->loggerPath,
            json_encode($this->subject->updateResult).PHP_EOL , FILE_APPEND
        );
        if ($result === null) {
            throw new RuntimeException("Write of data store file $this->loggerPath failed.");
        }
    }
}

// separate observers
interface MailMessengerInterface{}

class MailMessenger implements \SplObserver, MailMessengerInterface
{

    private const MAIL_TO = 'mailto@hostwhere.com';
    private const QOH_DIPS_FIVE = 'qoh dips five';
    private const MAIL_HEADER = [
        'From' => 'webmaster@hostwhere.com',
        'Reply-To' => 'webmaster@hostwhere.com',
        'X-Mailer' => 'PHP'
    ];
    private string $loggerPath;
    private SplSubject $subject;

    public function __construct(string $loggerPath) {
        $this->loggerPath = $loggerPath;

        if (!($this->loggerPath)) {
            throw new RuntimeException("Data store file $loggerPath cannot be null.");
        }

        if (file_exists($loggerPath) && !is_writable($this->loggerPath)) {
            throw new RuntimeException("Data store file $loggerPath must be writable.");
        }
    }

    public function update(SplSubject $subject): void
    {
        echo 'em::update::MailMessenger' . "\n";

        $this->subject = $subject;
        $this->save();
    }

    private function save(): void
    {
        $message = wordwrap(
              json_encode([
                              'sku'=>$this->subject->updateResult['sku'],
                              'qoh'=>$this->subject->updateResult['qoh'],
                          ], JSON_THROW_ON_ERROR)
            , 70, "\r\n");
        mail(self::MAIL_TO, self::QOH_DIPS_FIVE, $message, self::MAIL_HEADER);

        // see sent message contents
        $result = file_put_contents
        (
            $this->loggerPath,
            json_encode([
                            'sku'=>$this->subject->updateResult['sku'],
                            'qoh'=>$this->subject->updateResult['qoh'],
                        ], JSON_THROW_ON_ERROR) .PHP_EOL, FILE_APPEND
        );
        if ($result === null) {
            throw new RuntimeException("Write of data store file $this->loggerPath failed.");
        }

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
    public function itemsReceived($numberReceived): void
    {

        $newData = $this->_data;
        $current = $this->qoh;
        for($i = 1; $i <= $numberReceived; $i++) {
            //notifyWareHouse();  //Not implemented yet.
            $newData['qoh'] = ++$current;
        }
        $this->update($newData);
    }

    public function changeSalePrice($salePrice): void
    {
        $newData = $this->_data;

        $newData['saleprice'] = $salePrice;
        $this->update($newData);
    }

    public function getMembers(): array
    {
        //These are the field in the underlying data array
        return [
            'sku' => 1,
            'qoh' => 1,
            'cost' => 1,
            'saleprice' => 1
        ];
    }

    public function getPrimary(): string
    {
        //Which field constitutes the primary key in the storage class?
        return 'sku';
    }
}

function driver()
{
    $dataStorePath =__DIR__ . '/../../public/data_store_file.data';
    $dataLoggerPath =__DIR__ . '/../../public/logger_file.data';
    $entityManager = new EntityManager($dataStorePath);

    $fileLogger = new FileLogger($dataLoggerPath);
    $entityManager->attach($fileLogger);

    $mailMessenger =  new MailMessenger($dataLoggerPath);
    $entityManager->attach($mailMessenger);

    Entity::setDefaultEntityManager($entityManager);
//
    //create five new Inventory items
    /** @var InventoryItem $item1 */
    $item1 = Entity::getEntity(
        InventoryItem::class,
        ['sku' => 'abc-4589', 'qoh' => 0, 'cost' => '5.67', 'salePrice' => '7.27']);
    $item2 = Entity::getEntity(
        InventoryItem::class,
        ['sku' => 'hjg-3821', 'qoh' => 0, 'cost' => '7.89', 'salePrice' => '12.00']);
    $item3 = Entity::getEntity(
        InventoryItem::class,
        ['sku' => 'xrf-3827', 'qoh' => 0, 'cost' => '15.27', 'salePrice' => '19.99']);
    $item4 = Entity::getEntity(
        InventoryItem::class,
        ['sku' => 'eer-4521', 'qoh' => 0, 'cost' => '8.45', 'salePrice' => '1.03']);
    $item5 = Entity::getEntity(
        InventoryItem::class,
        ['sku' => 'qws-6783', 'qoh' => 0, 'cost' => '3.00', 'salePrice' => '4.97']);

    $item1->itemsReceived(4);

    $item2->itemsReceived(2);
    $item3->itemsReceived(12);
    $item4->itemsReceived(20);
    $item5->itemsReceived(1);

    $item3->itemsHaveShipped(5);
    $item4->itemsHaveShipped(16);

    $item4->changeSalePrice(0.87);

    $entityManager->updateStore();


    // start comment below if run app directly php source.php
    try {
        assertEquals(4, $item1->qoh, "asserted value of QOH cannot be other than 4");
    } catch (RuntimeException $r)
    {
        echo $r->getMessage();
    }

    try {
        assertEquals(2, $item2->qoh, "asserted value of QOH cannot be other than 2");
    } catch (RuntimeException $r)
    {
        echo $r->getMessage();
    }

    try {
        assertEquals(7, $item3->qoh, "asserted value of QOH cannot be other than 7");
    } catch (RuntimeException $r)
    {
        echo $r->getMessage();
    }

    try {
        assertEquals(4, $item4->qoh, "asserted value of QOH cannot be other than 4");
    } catch (RuntimeException $r)
    {
        echo $r->getMessage();
    }

    try {
        assertEquals(1, $item5->qoh, "asserted value of QOH cannot be other than 1");
    } catch (RuntimeException $r)
    {
        echo $r->getMessage();
    }


    try {
        assertEquals(1, $item5->qoh, "asserted value of QOH cannot be other than 1");
    } catch (RuntimeException $r)
    {
        echo $r->getMessage();
    }

    try {
        assertEquals(0.87, $item4->saleprice, "asserted value of salePrice cannot be other than 0.87");
    } catch (RuntimeException $r)
    {
        echo $r->getMessage();
    }
    // stop comment above if run app directly php source.php
}

driver();

