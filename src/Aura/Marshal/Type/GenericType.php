<?php
/**
 * 
 * This file is part of the Aura project for PHP.
 * 
 * @package Aura.Marshal
 * 
 * @license http://opensource.org/licenses/bsd-license.php BSD
 * 
 */
namespace Aura\Marshal\Type;

use Aura\Marshal\Collection\BuilderInterface as CollectionBuilderInterface;
use Aura\Marshal\Data;
use Aura\Marshal\Exception;
use Aura\Marshal\Entity\BuilderInterface as EntityBuilderInterface;
use Aura\Marshal\Relation\RelationInterface;
use SplObjectStorage;

/**
 * 
 * Describes a particular type within the domain, and retains an IdentityMap
 * of entities for the type. Converts loaded data to entity objects lazily.
 * 
 * @package Aura.Marshal
 * 
 */
class GenericType extends Data
{
    /**
     * 
     * A builder to create collection objects for this type.
     * 
     * @var object
     * 
     */
    protected $collection_builder;

    /**
     * 
     * The entity field representing its unique identifier value. The
     * IdentityMap will be keyed on these values.
     * 
     * @var string
     * 
     */
    protected $identity_field;

    /**
     * 
     * An index of entities on the identity field. The format is:
     * 
     *      $index_identity[$identity_value] = $offset;
     * 
     * Note that we always have only one offset, keyed by identity value.
     * 
     * @var array
     * 
     */
    protected $index_identity;

    /**
     * 
     * An index of all entities added via newEntity(). The format is:
     * 
     *      $index_new[] = $offset;
     * 
     * Note that we always have one offset, and the key is merely sequential.
     * 
     * @var array
     * 
     */
    protected $index_new;

    /**
     * 
     * An array of fields to index on for quicker lookups. The array format
     * is:
     * 
     *     $index_fields[$field_name][$field_value] = (array) $offsets;
     * 
     * Note that we always have an array of offsets, and the keys are by
     * the field name and the values for that field.
     * 
     * @var array
     * 
     */
    protected $index_fields = [];

    protected $initial_data;
    
    /**
     * 
     * A builder to create entity objects for this type.
     * 
     * @var object
     * 
     */
    protected $entity_builder;

    /**
     * 
     * An array of relationship descriptions, where the key is a
     * field name for the entity and the value is a relation object.
     * 
     * @var array
     * 
     */
    protected $relation = [];

    /**
     * 
     * Constructor.
     * 
     * @param array $data The data for this object.
     * 
     */
    public function __construct(array $data = [])
    {
        parent::__construct($data);
        $this->initial_data = new SplObjectStorage;
    }

    /**
     * 
     * Sets the name of the field that uniquely identifies each entity for
     * this type.
     * 
     * @param string $identity_field The identity field name.
     * 
     * @return void
     * 
     */
    public function setIdentityField($identity_field)
    {
        $this->identity_field = $identity_field;
    }

    /**
     * 
     * Returns the name of the field that uniquely identifies each entity of
     * this type.
     * 
     * @return string
     * 
     */
    public function getIdentityField()
    {
        return $this->identity_field;
    }

    /**
     * 
     * Sets the fields that should be indexed at load() time; removes all
     * previous field indexes.
     * 
     * @param array $fields The fields to be indexed.
     * 
     * @return void
     * 
     */
    public function setIndexFields(array $fields = [])
    {
        $this->index_fields = [];
        foreach ($fields as $field) {
            $this->index_fields[$field] = [];
        }
    }

    /**
     * 
     * Returns the list of indexed field names.
     * 
     * @return array
     * 
     */
    public function getIndexFields()
    {
        return array_keys($this->index_fields);
    }

    /**
     * 
     * Sets the builder object to create entity objects.
     * 
     * @param EntityBuilderInterface $entity_builder The builder object.
     * 
     * @return void
     * 
     */
    public function setEntityBuilder(EntityBuilderInterface $entity_builder)
    {
        $this->entity_builder = $entity_builder;
    }

    /**
     * 
     * Returns the builder that creates entity objects.
     * 
     * @return object
     * 
     */
    public function getEntityBuilder()
    {
        return $this->entity_builder;
    }

    /**
     * 
     * Sets the builder object to create collection objects.
     * 
     * @param CollectionBuilderInterface $collection_builder The builder object.
     * 
     * @return void
     * 
     */
    public function setCollectionBuilder(CollectionBuilderInterface $collection_builder)
    {
        $this->collection_builder = $collection_builder;
    }

    /**
     * 
     * Returns the builder that creates collection objects.
     * 
     * @return object
     * 
     */
    public function getCollectionBuilder()
    {
        return $this->collection_builder;
    }

    /**
     * 
     * Loads the IdentityMap for this type with data for entity objects. 
     * 
     * Typically, the $data value is a sequential array of associative arrays. 
     * As long as the $data value can be iterated over and accessed as an 
     * array, you can pass in any kind of $data.
     * 
     * The elements from $data will be placed into the IdentityMap and indexed
     * according to the value of their identity field.
     * 
     * You can call load() multiple times, but entities already in the 
     * IdentityMap will not be overwritten.
     * 
     * The loaded elements are cast to objects; this allows consistent
     * addressing of elements before and after conversion to entity objects.
     * 
     * The loaded elements will be converted to entity objects by the
     * entity builder only as you request them from the IdentityMap.
     * 
     * @param array $data Entity data to load into the IdentityMap.
     * 
     * @return GenericCollection
     * 
     */
    public function loadCollection($data)
    {
        // what indexes do we need to track?
        $index_fields = array_keys($this->index_fields);

        // retain identity values for creating a collection
        $identity_values = [];
        $identity_field  = $this->getIdentityField();
        
        // load each data element as a entity
        foreach ($data as $initial_data) {
            $initial_data = (object) $initial_data;
            $identity_values[] = $initial_data->$identity_field;
            $this->load($initial_data, $index_fields);
        }

        // done, return a collection
        return $this->getCollection($identity_values);
    }

    public function loadEntity($initial_data)
    {
        $initial_data = (object) $initial_data;
        $index_fields = array_keys($this->index_fields);
        return $this->load($initial_data, $index_fields);
    }
    
    protected function load($initial_data, $index_fields)
    {
        // get the identity value
        $identity_field  = $this->getIdentityField();
        $identity_value  = $initial_data->$identity_field;
        
        // if it exists, don't re-load it
        if (isset($this->index_identity[$identity_value])) {
            $offset = $this->index_identity[$identity_value];
            return $this->offsetGet($offset);
        }
        
        // convert to a entity of the proper type
        $entity = $this->entity_builder->newInstance($initial_data);

        // retain it in the identity map
        $this->data[] = $entity;
        
        // index identity by offset
        end($this->data);
        $offset = key($this->data);
        $this->index_identity[$identity_value] = $offset;
        
        // index other fields by offset
        foreach ($index_fields as $field) {
            $value = $entity->$field;
            $this->index_fields[$field][$value][] = $offset;
        }
        
        // retain initial data
        $this->initial_data->attach($entity, $initial_data);
        
        // done! return the loaded entity
        return $entity;
    }
    
    /**
     * 
     * Returns the array keys for the for the entities in the IdentityMap;
     * the keys were generated at load() time from the identity field values.
     * 
     * @return array
     * 
     */
    public function getIdentityValues()
    {
        return array_keys($this->index_identity);
    }

    /**
     * 
     * Returns the values for a particular field for all the entities in the
     * IdentityMap.
     * 
     * @param string $field The field name to get values for.
     * 
     * @return array An array of key-value pairs where the key is the identity
     * value and the value is the requested field value.
     * 
     */
    public function getFieldValues($field)
    {
        $values = [];
        $identity_field = $this->getIdentityField();
        foreach ($this->data as $offset => $entity) {
            $identity_value = $entity->$identity_field;
            $values[$identity_value] = $entity->$field;
        }
        return $values;
    }

    /**
     * 
     * Retrieves a single entity from the IdentityMap by the value of its
     * identity field.
     * 
     * @param int $identity_value The identity value of the entity to be
     * retrieved.
     * 
     * @return object A entity object via the entity builder.
     * 
     */
    public function getEntity($identity_value)
    {
        // if the entity is not in the identity index, exit early
        if (! isset($this->index_identity[$identity_value])) {
            return null;
        }

        // look up the sequential offset for the identity value
        $offset = $this->index_identity[$identity_value];
        return $this->offsetGet($offset);
    }

    /**
     * 
     * Retrieves the first entity from the IdentityMap that matches the value
     * of an arbitrary field; it will be converted to a entity object
     * if it is not already an object of the proper class.
     * 
     * N.b.: This will not be performant for large sets where the field is not
     * an identity field and is not indexed.
     * 
     * @param string $field The field to match on.
     * 
     * @param mixed $value The value of the field to match on.
     * 
     * @return object A entity object via the entity builder.
     * 
     */
    public function getEntityByField($field, $value)
    {
        // pre-emptively look for an identity field
        if ($field == $this->identity_field) {
            return $this->getEntity($value);
        }

        // pre-emptively look for an indexed field for that value
        if (isset($this->index_fields[$field])) {
            return $this->getEntityByIndex($field, $value);
        }

        // long slow loop through all the entities to find a match.
        foreach ($this->data as $offset => $entity) {
            if ($entity->$field == $value) {
                return $this->offsetGet($offset);
            }
        }

        // no match!
        return null;
    }

    /**
     * 
     * Retrieves the first entity from the IdentityMap matching an index 
     * lookup.
     * 
     * @param string $field The indexed field name.
     * 
     * @param string $value The field value to match on.
     * 
     * @return object A entity object via the entity builder.
     * 
     */
    protected function getEntityByIndex($field, $value)
    {
        if (! isset($this->index_fields[$field][$value])) {
            return null;
        }
        $offset = $this->index_fields[$field][$value][0];
        return $this->offsetGet($offset);
    }

    /**
     * 
     * Retrieves a collection of elements from the IdentityMap by the values
     * of their identity fields; each element will be converted to a entity 
     * object if it is not already an object of the proper class.
     * 
     * @param array $identity_values An array of identity values to retrieve.
     * 
     * @return object A collection object via the collection builder.
     * 
     */
    public function getCollection(array $identity_values)
    {
        $list = [];
        foreach ($identity_values as $identity_value) {
            // look up the offset for the identity value
            $offset = $this->index_identity[$identity_value];
            // assigning by reference keeps the connections
            // when the element is converted to a entity
            $list[] =& $this->data[$offset];
        }
        return $this->collection_builder->newInstance($list);
    }

    /**
     * 
     * Retrieves a collection of objects from the IdentityMap matching the 
     * value of an arbitrary field; these will be converted to entities 
     * if they are not already objects of the proper class.
     * 
     * The value to be matched can be an array of values, so that you
     * can get many values of the field being matched.
     * 
     * If the field is indexed, the order of the returned collection
     * will match the order of the values being searched. If the field is not
     * indexed, the order of the returned collection will be the same as the 
     * IdentityMap.
     * 
     * The fastest results are from the identity field; second fastest, from
     * an indexed field; slowest are from non-indexed fields, because it has
     * to look through the entire IdentityMap to find matches.
     * 
     * @param string $field The field to match on.
     * 
     * @param mixed $values The value of the field to match on; if an array,
     * any value in the array will be counted as a match.
     * 
     * @return object A collection object via the collection builder.
     * 
     */
    public function getCollectionByField($field, $values)
    {
        $values = (array) $values;

        // pre-emptively look for an identity field
        if ($field == $this->identity_field) {
            return $this->getCollection($values);
        }

        // pre-emptively look for an indexed field
        if (isset($this->index_fields[$field])) {
            return $this->getCollectionByIndex($field, $values);
        }

        // long slow loop through all the entities to find a match
        $list = [];
        foreach ($this->data as $identity_value => $entity) {
            if (in_array($entity->$field, $values)) {
                // assigning by reference keeps the connections
                // when the original is converted to a entity
                $list[] =& $this->data[$identity_value];
            }
        }
        return $this->collection_builder->newInstance($list);
    }

    /**
     * 
     * Looks through the index for a field to retrieve a collection of
     * objects from the IdentityMap; these will be converted to entities 
     * if they are not already objects of the proper class.
     * 
     * N.b.: The value to be matched can be an array of values, so that you
     * can get many values of the field being matched.
     * 
     * N.b.: The order of the returned collection will match the order of the
     * values being searched, not the order of the entities in the IdentityMap.
     * 
     * @param string $field The field to match on.
     * 
     * @param mixed $values The value of the field to match on; if an array,
     * any value in the array will be counted as a match.
     * 
     * @return object A collection object via the collection builder.
     * 
     */
    protected function getCollectionByIndex($field, $values)
    {
        $values = (array) $values;
        $list = [];
        foreach ($values as $value) {
            // is there an index for that field value?
            if (isset($this->index_fields[$field][$value])) {
                // assigning by reference keeps the connections
                // when the original is converted to a entity.
                foreach ($this->index_fields[$field][$value] as $offset) {
                    $list[] =& $this->data[$offset];
                }
            }
        }
        return $this->collection_builder->newInstance($list);
    }

    /**
     * 
     * Sets a relationship to another type, assigning it to a field
     * name to be used in entity objects.
     * 
     * @param string $name The field name to use for the related entity
     * or collection.
     * 
     * @param RelationInterface $relation The relationship definition object.
     * 
     * @return void
     * 
     */
    public function setRelation($name, RelationInterface $relation)
    {
        if (isset($this->relation[$name])) {
            throw new Exception("Relation '$name' already exists.");
        }
        $this->relation[$name] = $relation;
    }

    /**
     * 
     * Returns a relationship definition object by name.
     * 
     * @param string $name The field name to use for the related entity
     * or collection.
     * 
     * @return AbstractRelation
     * 
     */
    public function getRelation($name)
    {
        return $this->relation[$name];
    }

    /**
     * 
     * Returns all the names of the relationship definition objects.
     * 
     * @return array
     * 
     */
    public function getRelationNames()
    {
        return array_keys($this->relation);
    }

    /**
     * 
     * Adds a new entity to the IdentityMap.
     * 
     * This entity will not show up in any indexes, whether by field or
     * by primary key. You will see it only by iterating through the
     * IdentityMap. Typically this is used to add to a collection, or
     * to create a new entity from user input.
     * 
     * @param array $data Data for the new entity.
     * 
     * @return object
     * 
     */
    public function newEntity(array $initial_data = [])
    {
        $entity = $this->entity_builder->newInstance($initial_data);
        $this->index_new[] = count($this->data);
        $this->data[] = $entity;
        return $entity;
    }

    /**
     * 
     * Returns an array of all entities in the IdentityMap that have been 
     * modified.
     * 
     * @return array
     * 
     */
    public function getChangedEntities()
    {
        $list = [];
        foreach ($this->index_identity as $identity_value => $offset) {
            $entity = $this->offsetGet($offset);
            if ($this->getChangedFields($entity)) {
                $list[$identity_value] = $entity;
            }
        }
        return $list;
    }

    /**
     * 
     * Returns an array of all entities in the IdentityMap that were created
     * using `newEntity()`.
     * 
     * @return array
     * 
     */
    public function getNewEntities()
    {
        $list = [];
        foreach ($this->index_new as $offset) {
            $list[] = $this->data[$offset];
        }
        return $list;
    }
    
    public function getRelated($entity, $relation_name)
    {
        $relation = $this->getRelation($relation_name);
        return $relation->getForEntity($entity);
    }
    
    public function getInitialData($entity)
    {
        if ($this->initial_data->contains($entity)) {
            return $this->initial_data[$entity];
        } else {
            return null;
        }
    }
    
    public function getChangedFields($entity)
    {
        // the eventual list of changed fields and values
        $changed = [];

        // initial data for this entity
        $initial_data = $this->getInitialData($entity);
        if (! $initial_data) {
            foreach ($entity as $field => $value) {
                $changed[$field] = $value;
            }
            return $changed;
        }
        
        // go through all the data elements and their presumed new values
        foreach ($entity as $field => $new) {

            // if the field is not part of the initial data ...
            if (! array_key_exists($field, $initial_data)) {
                // ... then it's a change from the initial data.
                $changed[$field] = $new;
                continue;
            }

            // what was the old (initial) value?
            $old = $initial_data->$field;

            // are both old and new values numeric?
            $numeric = is_numeric($old) && is_numeric($new);

            // if both old and new are numeric, compare loosely.
            if ($numeric && $old != $new) {
                // loosely different, retain the new value
                $changed[$field] = $new;
            }

            // if one or the other is not numeric, compare strictly
            if (! $numeric && $old !== $new) {
                // strictly different, retain the new value
                $changed[$field] = $new;
            }
        }

        // done!
        return $changed;
    }
}
