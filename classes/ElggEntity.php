<?php

use Minds\Entities\EntityInterface;
use Minds\Helpers\StringLengthValidators\MessageLengthValidator;
use Minds\Helpers\StringLengthValidators\TitleLengthValidator;

/**
 * The parent class for all Elgg Entities.
 *
 *
 * @package    Elgg.Core
 * @subpackage DataModel.Entities
 *
 * @property string $type           object, user, group, or site (read-only after save)
 * @property string $subtype        Further clarifies the nature of the entity (read-only after save)
 * @property int    $guid           The unique identifier for this entity (read only)
 * @property int    $owner_guid     The GUID of the creator of this entity
 * @property int    $container_guid The GUID of the entity containing this entity
 * @property int    $site_guid      The GUID of the website this entity is associated with
 * @property int    $access_id      Specifies the visibility level of this entity
 * @property int    $time_created   A UNIX timestamp of when the entity was created (read-only, set on first save)
 * @property int    $time_updated   A UNIX timestamp of when the entity was last updated (automatically updated on save)
 * @property int    $moderator_guid The GUID of the moderator
 * @property int    $moderated_at   A UNIX timestamp of when the entity was moderated
 * @property bool   $allow_comments A boolean value that turns off comments for an entity
 * @property-read string $enabled
 */
abstract class ElggEntity extends ElggData implements
    Importable, // Allow import of data
    EntityInterface
{
    protected $cache = true;

    /**
     * If set, overrides the value of getURL()
     */
    protected $url_override;

    /**
     * Icon override, overrides the value of getIcon().
     */
    protected $icon_override;


    /**
     * Volatile data structure for this object, allows for storage of data
     * in-memory that isn't sync'd back to the metadata table.
     */
    protected $volatile = [];

    protected $_context = 'default';

    /**
     * Default a fields isJSON checks to false.
     */
    protected $nonJsonFields = [
        'message',
        'description',
        'title'
    ];

    /**
     * Initialize the attributes array.
     *
     * This is vital to distinguish between metadata and base parameters.
     *
     * @return void
     */
    protected function initializeAttributes()
    {
        parent::initializeAttributes();

        $this->attributes['guid'] = null;
        $this->attributes['type'] = null;
        $this->attributes['subtype'] = null;

        $this->attributes['owner_guid'] = elgg_get_logged_in_user_guid();
        $this->attributes['container_guid'] = elgg_get_logged_in_user_guid();

        $this->attributes['site_guid'] = null;
        $this->attributes['access_id'] = get_default_access();
        $this->attributes['time_created'] = time();
        $this->attributes['time_updated'] = time();
        $this->attributes['last_action'] = null;
        $this->attributes['enabled'] = "yes";
        $this->attributes['tags'] = null;
        $this->attributes['nsfw'] = [];
        $this->attributes['nsfw_lock'] = [];
        $this->attributes['moderator_guid'] = null;
        $this->attributes['time_moderated'] = null;
        $this->attributes['allow_comments'] = true;
    }

    /**
     * Entity constructor
     */
    public function __construct($guid = null)
    {
        $this->initializeAttributes();

        if ($guid) {
            if (is_numeric($guid)) {
                $this->loadFromGUID($guid);
            } elseif (is_object($guid)) {
                $this->loadFromObject($guid);
            } elseif (is_array($guid)) {
                $this->loadFromArray($guid);
            }
        }
    }

    protected function loadFromGUID($guid)
    {
        $db = new Minds\Core\Data\Call('entities');
        $row = $db->getRow($guid, ['limit'=>400]);
        if ($row) {
            $row['guid'] = $guid;
            $this->loadFromArray($row);
        }
    }

    protected function loadFromObject($object)
    {
        $this->loadFromArray($object);
    }

    protected function loadFromArray($array)
    {
        foreach ($array as $k=>$v) {
            if ($this->isJson($v, $k)) {
                $v = json_decode($v, true);
            }

            $this->$k = $v;
        }

        if ($this->cache) {
            cache_entity($this);
        }
    }


    /**
     * Returns true if string contains valid JSON that is not on the exceptions list.
     * @param $string - string to check.
     * @param $key - key to check.
     * @return bool - true if valid json not on exceptions list.
     */
    public function isJson($string, $key = ''): bool
    {
        // if its not a string, false
        if (!is_string($string)) {
            return false;
        }

        try {
            // 'true' and 'false' are valid for some fields.
            if (in_array($key, $this->nonJsonFields, true)) {
                return false;
            }
        } catch (\Exception $e) {
            // do nothing.
        }

        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }
    
    /**
     * Return the value of a property.
     *
     * @param string $name Name
     *
     * @return mixed Returns the value of a given value, or null.
     */
    public function get($name)
    {
        // See if its in our base attributes
        if (array_key_exists($name, $this->attributes)) {
            return $this->attributes[$name];
        }

        return false;
    }

    public function __set($name, $value)
    {
        return $this->set($name, $value);
    }
    /**
     * Sets the value of a property.
     *
     * @param string $name  Name
     * @param mixed  $value Value
     *
     * @return bool
     */
    public function set($name, $value)
    {
        switch ($name) {
            case 'access_id': // a hack to fix listings.
                if ($value == ACCESS_DEFAULT) {
                    $value = get_default_access($this->getOwnerEntity());
                }
                    // no break
            default:
                $this->attributes[$name] = $value;
                break;
        }
        if ($this->guid) {
            //		$this->save();
        }
        return $this;
    }

    /**
     * @deprecated
     */
    public function getMetaData($name)
    {
        return false;
    }

    /**
     * Unset a property from metadata or attribute.
     *
     * @warning If you use this to unset an attribute, you must save the object!
     *
     * @param string $name The name of the attribute or metadata.
     *
     * @return void
     */
    public function __unset($name)
    {
        if (array_key_exists($name, $this->attributes)) {
            $this->attributes[$name] = "";
        }
    }

    /**
     * @deprecated
     */
    public function setMetaData($name, $value, $value_type = null, $multiple = false)
    {
        return false;
    }

    /**
     * @deprecated
     */
    public function deleteMetadata($name = null)
    {
        return false;
    }

    /**
     * @deprecated
     */
    public function deleteOwnedMetadata($name = null)
    {
        return false;
    }

    /**
     * @deprecated
     */
    public function clearMetaData($name = '')
    {
        return false;
    }

    /**
     * @deprecated
     */
    public function disableMetadata($name = '')
    {
        return false;
    }

    /**
     * @deprecated
     */
    public function enableMetadata($name = '')
    {
        return false;
    }

    /**
     * Get a piece of volatile (non-persisted) data on this entity.
     *
     * @param string $name The name of the volatile data
     *
     * @return mixed The value or NULL if not found.
     */
    public function getVolatileData($name)
    {
        if (!is_array($this->volatile)) {
            $this->volatile = [];
        }

        if (array_key_exists($name, $this->volatile)) {
            return $this->volatile[$name];
        } else {
            return null;
        }
    }

    /**
     * Set a piece of volatile (non-persisted) data on this entity
     *
     * @param string $name  Name
     * @param mixed  $value Value
     *
     * @return void
     */
    public function setVolatileData($name, $value)
    {
        if (!is_array($this->volatile)) {
            $this->volatile = [];
        }

        $this->volatile[$name] = $value;
    }

    /**
     * Remove all relationships to and from this entity.
     *
     * @return true
     * @todo This should actually return if it worked.
     * @see ElggEntity::addRelationship()
     * @see ElggEntity::removeRelationship()
     */
    public function deleteRelationships()
    {
        remove_entity_relationships($this->getGUID());
        remove_entity_relationships($this->getGUID(), "", true);
        return true;
    }

    /**
     * Remove all relationships to and from this entity.
     *
     * @return bool
     * @see ElggEntity::addRelationship()
     * @see ElggEntity::removeRelationship()
     * @deprecated 1.8 Use ->deleteRelationship()
     */
    public function clearRelationships()
    {
        elgg_deprecated_notice('ElggEntity->clearRelationships() is deprecated by ->deleteRelationships()', 1.8);
        return $this->deleteRelationships();
    }

    /**
     * Add a relationship between this an another entity.
     *
     * @tip Read the relationship like "$guid is a $relationship of this entity."
     *
     * @param int    $guid         Entity to link to.
     * @param string $relationship The type of relationship.
     *
     * @return bool
     * @see ElggEntity::removeRelationship()
     * @see ElggEntity::clearRelationships()
     */
    public function addRelationship($guid, $relationship)
    {
        return add_entity_relationship($this->getGUID(), $relationship, $guid);
    }

    /**
     * Remove a relationship
     *
     * @param int $guid         GUID of the entity to make a relationship with
     * @param str $relationship Name of relationship
     *
     * @return bool
     * @see ElggEntity::addRelationship()
     * @see ElggEntity::clearRelationships()
     */
    public function removeRelationship($guid, $relationship)
    {
        return remove_entity_relationship($this->getGUID(), $relationship, $guid);
    }

    /**
     * Adds a private setting to this entity.
     *
     * Since the move to cassandra, attributes have been merged.
     * Therefore, this funciton will be soon deprecated and replaced with
     * a single set function.
     *
     * @param string $name  Name of private setting
     * @param mixed  $value Value of private setting
     *
     * @return bool
     */
    public function setPrivateSetting($name, $value)
    {
        if ($this->guid) {
            $this->$name = $value;
            return	$this->save();
        } else {
            $this->temp_private_settings[$name] = $value;
            return true;
        }
    }

    /**
     * Returns a private setting value
     *
     * @param string $name Name of the private setting
     *
     * @return mixed
     */
    public function getPrivateSetting($name)
    {
        return $this->$name;
    }

    /**
     * Removes private setting
     *
     * @param string $name Name of the private setting
     *
     * @return bool
     */
    public function removePrivateSetting($name)
    {
        return remove_private_setting($this->getGUID(), $name);
    }

    /**
     * Deletes all annotations on this object (annotations.entity_guid = $this->guid).
     * If you pass a name, only annotations matching that name will be deleted.
     *
     * @warning Calling this with no or empty arguments will clear all annotations on the entity.
     *
     * @param null|string $name The annotations name to remove.
     * @return bool
     * @since 1.8
     */
    public function deleteAnnotations($name = null)
    {
        $options = [
            'guid' => $this->guid,
            'limit' => 0
        ];
        if ($name) {
            $options['annotation_name'] = $name;
        }

        return elgg_delete_annotations($options);
    }

    /**
     * Deletes all annotations owned by this object (annotations.owner_guid = $this->guid).
     * If you pass a name, only annotations matching that name will be deleted.
     *
     * @param null|string $name The name of annotations to delete.
     * @return bool
     * @since 1.8
     */
    public function deleteOwnedAnnotations($name = null)
    {
        // access is turned off for this because they might
        // no longer have access to an entity they created annotations on.
        $ia = elgg_set_ignore_access(true);
        $options = [
            'annotation_owner_guid' => $this->guid,
            'limit' => 0
        ];
        if ($name) {
            $options['annotation_name'] = $name;
        }

        $r = elgg_delete_annotations($options);
        elgg_set_ignore_access($ia);
        return $r;
    }

    /**
     * Disables annotations for this entity, optionally based on name.
     *
     * @param string $name An options name of annotations to disable.
     * @return bool
     * @since 1.8
     */
    public function disableAnnotations($name = '')
    {
        $options = [
            'guid' => $this->guid,
            'limit' => 0
        ];
        if ($name) {
            $options['annotation_name'] = $name;
        }

        return elgg_disable_annotations($options);
    }

    /**
     * Enables annotations for this entity, optionally based on name.
     *
     * @warning Before calling this, you must use {@link access_show_hidden_entities()}
     *
     * @param string $name An options name of annotations to enable.
     * @return bool
     * @since 1.8
     */
    public function enableAnnotations($name = '')
    {
        $options = [
            'guid' => $this->guid,
            'limit' => 0
        ];
        if ($name) {
            $options['annotation_name'] = $name;
        }

        return elgg_enable_annotations($options);
    }

    /**
     * Helper function to return annotation calculation results
     *
     * @param string $name        The annotation name.
     * @param string $calculation A valid MySQL function to run its values through
     * @return mixed
     */
    private function getAnnotationCalculation($name, $calculation)
    {
        $options = [
            'guid' => $this->getGUID(),
            'annotation_name' => $name,
            'annotation_calculation' => $calculation
        ];

        return elgg_get_annotations($options);
    }

    /**
     * Adds an annotation to an entity.
     *
     * @warning By default, annotations are private.
     *
     * @warning Annotating an unsaved entity more than once with the same name
     *          will only save the last annotation.
     *
     * @param string $name      Annotation name
     * @param mixed  $value     Annotation value
     * @param int    $access_id Access ID
     * @param int    $owner_id  GUID of the annotation owner
     * @param string $vartype   The type of annotation value
     *
     * @return bool
     */
    public function annotate($name, $value, $access_id = ACCESS_PRIVATE, $owner_id = 0, $vartype = "")
    {
        if ((int) $this->guid > 0) {
            return create_annotation($this->getGUID(), $name, $value, $vartype, $owner_id, $access_id);
        } else {
            $this->temp_annotations[$name] = $value;
        }
        return true;
    }

    /**
     * Returns an array of annotations.
     *
     * @param string $name   Annotation name
     * @param int    $limit  Limit
     * @param int    $offset Offset
     * @param string $order  Order by time: asc or desc
     *
     * @return array
     */
    public function getAnnotations($name, $limit = 50, $offset = 0, $order = "asc")
    {
        if ((int) ($this->guid) > 0) {
            $options = [
                'guid' => $this->guid,
                'annotation_name' => $name,
                'limit' => $limit,
                'offset' => $offset,
            ];

            if ($order != 'asc') {
                $options['reverse_order_by'] = true;
            }

            return elgg_get_annotations($options);
        } elseif (isset($this->temp_annotations[$name])) {
            return [$this->temp_annotations[$name]];
        } else {
            return [];
        }
    }

    /**
     * Remove an annotation or all annotations for this entity.
     *
     * @warning Calling this method with no or an empty argument will remove
     * all annotations on the entity.
     *
     * @param string $name Annotation name
     * @return bool
     * @deprecated 1.8 Use ->deleteAnnotations()
     */
    public function clearAnnotations($name = "")
    {
        elgg_deprecated_notice('ElggEntity->clearAnnotations() is deprecated by ->deleteAnnotations()', 1.8);
        return $this->deleteAnnotations($name);
    }

    /**
     * Count annotations.
     *
     * @param string $name The type of annotation.
     *
     * @return int
     */
    public function countAnnotations($name = "")
    {
        return $this->getAnnotationCalculation($name, 'count');
    }

    /**
     * Get the average of an integer type annotation.
     *
     * @param string $name Annotation name
     *
     * @return int
     */
    public function getAnnotationsAvg($name)
    {
        return $this->getAnnotationCalculation($name, 'avg');
    }

    /**
     * Get the sum of integer type annotations of a given name.
     *
     * @param string $name Annotation name
     *
     * @return int
     */
    public function getAnnotationsSum($name)
    {
        return $this->getAnnotationCalculation($name, 'sum');
    }

    /**
     * Get the minimum of integer type annotations of given name.
     *
     * @param string $name Annotation name
     *
     * @return int
     */
    public function getAnnotationsMin($name)
    {
        return $this->getAnnotationCalculation($name, 'min');
    }

    /**
     * Get the maximum of integer type annotations of a given name.
     *
     * @param string $name Annotation name
     *
     * @return int
     */
    public function getAnnotationsMax($name)
    {
        return $this->getAnnotationCalculation($name, 'max');
    }

    /**
     * Count the number of comments attached to this entity.
     *
     * @return int Number of comments
     * @since 1.8.0
     */
    public function countComments()
    {
        $params = ['entity' => $this];
        $num = elgg_trigger_plugin_hook('comments:count', $this->getType(), $params);

        if (is_int($num)) {
            return $num;
        } else {
            return $this->getAnnotationCalculation('generic_comment', 'count');
        }
    }

    /**
     * Gets an array of entities with a relationship to this entity.
     *
     * @param string $relationship Relationship type (eg "friends")
     * @param bool   $inverse      Is this an inverse relationship?
     * @param int    $limit        Number of elements to return
     * @param int    $offset       Indexing offset
     *
     * @return array|false An array of entities or false on failure
     */
    public function getEntitiesFromRelationship($relationship, $inverse = false, $limit = 50, $offset = 0)
    {
        return elgg_get_entities_from_relationship([
            'relationship' => $relationship,
            'relationship_guid' => $this->getGUID(),
            'inverse_relationship' => $inverse,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }

    /**
     * Gets the number of of entities from a specific relationship type
     *
     * @param string $relationship         Relationship type (eg "friends")
     * @param bool   $inverse_relationship Invert relationship
     *
     * @return int|false The number of entities or false on failure
     */
    public function countEntitiesFromRelationship($relationship, $inverse_relationship = false)
    {
        return elgg_get_entities_from_relationship([
            'relationship' => $relationship,
            'relationship_guid' => $this->getGUID(),
            'inverse_relationship' => $inverse_relationship,
            'count' => true
        ]);
    }

    /**
     * Can a user edit this entity.
     *
     * @param int $user_guid The user GUID, optionally (default: logged in user)
     *
     * @return bool
     */
    public function canEdit($user_guid = 0)
    {
        return Minds\Core\Security\ACL::_()->write($this);
    }

    /**
     * Can a user edit metadata on this entity
     *
     * @param ElggMetadata $metadata  The piece of metadata to specifically check
     * @param int          $user_guid The user GUID, optionally (default: logged in user)
     *
     * @return bool
     */
    public function canEditMetadata($metadata = null, $user_guid = 0)
    {
        return can_edit_entity_metadata($this->getGUID(), $user_guid, $metadata);
    }

    /**
     * Can a user add an entity to this container
     *
     * @param int    $user_guid The user.
     * @param string $type      The type of entity we're looking to write
     * @param string $subtype   The subtype of the entity we're looking to write
     *
     * @return bool
     */
    public function canWriteToContainer($user_guid = 0, $type = 'all', $subtype = 'all')
    {
        return can_write_to_container($user_guid, $this->guid, $type, $subtype);
    }

    /**
     * Can a user comment on an entity?
     *
     * @tip Can be overridden by registering for the permissions_check:comment,
     * <entity type> plugin hook.
     *
     * @param int $user_guid User guid (default is logged in user)
     *
     * @return bool
     */
    public function canComment($user_guid = 0)
    {
        if ($user_guid == 0) {
            $user_guid = elgg_get_logged_in_user_guid();
        }
        $user = get_entity($user_guid);

        // By default, we don't take a position of whether commenting is allowed
        // because it is handled by the subclasses of ElggEntity
        $params = ['entity' => $this, 'user' => $user];
        return elgg_trigger_plugin_hook('permissions_check:comment', $this->type, $params, null);
    }

    /**
     * Can a user annotate an entity?
     *
     * @tip Can be overridden by registering for the permissions_check:annotate,
     * <entity type> plugin hook.
     *
     * @tip If you want logged out users to annotate an object, do not call
     * canAnnotate(). It's easier than using the plugin hook.
     *
     * @param int    $user_guid       User guid (default is logged in user)
     * @param string $annotation_name The name of the annotation (default is unspecified)
     *
     * @return bool
     */
    public function canAnnotate($user_guid = 0, $annotation_name = '')
    {
        if ($user_guid == 0) {
            $user_guid = elgg_get_logged_in_user_guid();
        }
        $user = get_entity($user_guid, 'user');

        $return = true;
        if (!$user) {
            $return = false;
        }

        $params = [
            'entity' => $this,
            'user' => $user,
            'annotation_name' => $annotation_name,
        ];
        return elgg_trigger_plugin_hook('permissions_check:annotate', $this->type, $params, $return);
    }

    /**
     * Returns the access_id.
     *
     * @return string The access ID
     */
    public function getAccessId(): string
    {
        return (string) $this->get('access_id');
    }

    /**
     * Setter for access_id.
     * @return self instance of this.
     */
    public function setAccessId(string $accessId): self
    {
        $this->access_id = $accessId;
        return $this;
    }

    /**
     * Returns the guid.
     *
     * @return int|null GUID
     */
    public function getGuid(): ?string
    {
        return (string) $this->get('guid');
    }

    /**
     * Returns the entity type
     *
     * @return string|null Entity type
     */
    public function getType(): ?string
    {
        return $this->get('type');
    }

    /**
     * Returns the entity subtype string
     *
     * @note This returns a string.  If you want the id, use ElggEntity::subtype.
     *
     * @return string The entity subtype
     */
    public function getSubtype(): ?string
    {
        return $this->subtype;
    }

    /**
     * Get the guid of the entity's owner.
     *
     * @return int The owner GUID
     */
    public function getOwnerGuid(): ?string
    {
        return (string) $this->owner_guid;
    }

    /**
     * Return the guid of the entity's owner.
     *
     * @return int The owner GUID
     * @deprecated 1.8 Use getOwnerGUID()
     */
    public function getOwner()
    {
        elgg_deprecated_notice("ElggEntity::getOwner deprecated for ElggEntity::getOwnerGUID", 1.8);
        return $this->getOwnerGUID();
    }

    /**
     * Gets the ElggEntity that owns this entity.
     *
     * @return ElggEntity The owning entity
     */
    public function getOwnerEntity($brief = false)
    {
        if ($brief && isset($this->ownerObj)) {
            $owner = is_array($this->ownerObj) || is_object($this->ownerObj) ? $this->ownerObj : json_decode($this->ownerObj, true);
            if (is_object($this->ownerObj)) {
                $owner = json_decode(json_encode($this->ownerObj), true);
            }
            if (isset($owner['name']) || (is_object($owner) && $owner->name)) {
                return new Minds\Entities\User($owner);
            } else {
                if ($this->canEdit()) {
                    //$this->save();
                }
            }
        }

        return new Minds\Entities\User($this->owner_guid);
    }

    /**
     * Set the container for this object.
     *
     * @param int $container_guid The ID of the container.
     *
     * @return bool
     */
    public function setContainerGUID($container_guid)
    {
        $container_guid = (int)$container_guid;

        return $this->set('container_guid', $container_guid);
    }

    /**
     * Set the container for this object.
     *
     * @param int $container_guid The ID of the container.
     *
     * @return bool
     * @deprecated 1.8 use setContainerGUID()
     */
    public function setContainer($container_guid)
    {
        elgg_deprecated_notice("ElggObject::setContainer deprecated for ElggEntity::setContainerGUID", 1.8);
        $container_guid = (int)$container_guid;

        return $this->set('container_guid', $container_guid);
    }

    /**
     * Gets the container GUID for this entity.
     *
     * @return int
     */
    public function getContainerGUID()
    {
        return $this->get('container_guid');
    }

    /**
     * Gets the container GUID for this entity.
     *
     * @return int
     * @deprecated 1.8 Use getContainerGUID()
     */
    public function getContainer()
    {
        elgg_deprecated_notice("ElggObject::getContainer deprecated for ElggEntity::getContainerGUID", 1.8);
        return $this->get('container_guid');
    }

    /**
     * Get the container entity for this object.
     * Assume contrainer entity is a user, unless another class overrides this...
     *
     * @return ElggEntity
     * @since 1.8.0
     */
    public function getContainerEntity()
    {
        return get_entity($this->getContainerGUID());
    }

    /**
     * Returns the UNIX epoch time that this entity was last updated
     *
     * @return int UNIX epoch time
     */
    public function getTimeUpdated()
    {
        return $this->get('time_updated');
    }

    /**
     * Returns the URL for this entity
     *
     * @return string The URL
     * @see register_entity_url_handler()
     * @see ElggEntity::setURL()
     */
    public function getURL()
    {
        if (!empty($this->url_override)) {
            return $this->url_override;
        }
        return get_entity_url($this->getGUID(), $this->type);
    }

    public function getPermaURL()
    {
        return $this->perma_url ?: $this->getURL();
    }

    /**
     * Overrides the URL returned by getURL()
     *
     * @warning This override exists only for the life of the object.
     *
     * @param string $url The new item URL
     *
     * @return string The URL
     */
    public function setURL($url)
    {
        $this->url_override = $url;
        return $url;
    }

    /**
     * Get the URL for this entity's icon
     *
     * Plugins can register for the 'entity:icon:url', <type> plugin hook
     * to customize the icon for an entity.
     *
     * @param string $size Size of the icon: tiny, small, medium, large
     *
     * @return string The URL
     * @since 1.8.0
     */
    public function getIconURL($size = 'medium')
    {
        $size = strtolower($size);

        if (isset($this->icon_override[$size])) {
            elgg_deprecated_notice("icon_override on an individual entity is deprecated", 1.8);
            return $this->icon_override[$size];
        }

        $type = $this->getType();
        $params = [
            'entity' => $this,
            'size' => $size,
        ];

        $url = elgg_trigger_plugin_hook('entity:icon:url', $type, $params, null);
        if ($url == null) {
            $url = "_graphics/icons/default/$size.png";
        }

        return $url;
    }

    /**
     * Returns a URL for the entity's icon.
     *
     * @param string $size Either 'large', 'medium', 'small' or 'tiny'
     *
     * @return string The url or false if no url could be worked out.
     * @deprecated Use getIconURL()
     */
    public function getIcon($size = 'medium')
    {
        elgg_deprecated_notice("getIcon() deprecated by getIconURL()", 1.8);
        return $this->getIconURL($size);
    }

    /**
     * Set an icon override for an icon and size.
     *
     * @warning This override exists only for the life of the object.
     *
     * @param string $url  The url of the icon.
     * @param string $size The size its for.
     *
     * @return bool
     * @deprecated 1.8 See getIconURL() for the plugin hook to use
     */
    public function setIcon($url, $size = 'medium')
    {
        elgg_deprecated_notice("icon_override on an individual entity is deprecated", 1.8);

        if (!$this->icon_override) {
            $this->icon_override = [];
        }
        $this->icon_override[$size] = $url;

        return true;
    }

    /**
     * Tests to see whether the object has been fully loaded.
     *
     * @return bool
     */
    public function isFullyLoaded()
    {
        return true;
    }

    /**
     * Save an entity.
     *
     * @return bool|int
     * @throws IOException
     */
    public function save($timebased = true)
    {
        $isUpdate = false;
        if ($this->guid) {
            if (!$this->canEdit()) {
                return false;
            }
            $isUpdate = true;
            $this->time_updated = time();
            elgg_trigger_event('update', $this->type, $this);
            //@todo review... memecache actually make us slower anyway.. do we need it?
            if (is_memcache_available()) {
                $memcache = new ElggMemcache('new_entity_cache');
                $memcache->delete($this->guid);
            }
        } else {
            $this->guid = Minds\Core\Guid::build();
            elgg_trigger_event('create', $this->type, $this);
            if (!$this->canEdit()) {
                return false;
            }
        }

        $db = new Minds\Core\Data\Call('entities');
        $result = $db->insert($this->guid, $this->toArray());
        if ($result && $timebased) {
            $db = new Minds\Core\Data\Call('entities_by_time');
            $data = [$result => $result];

            foreach ($this->getIndexKeys() as $index) {
                $db->insert($index, $data);
            }
        }

        \Minds\Core\Events\Dispatcher::trigger('entities-ops', $isUpdate ? 'update' : 'create', [
            'entityUrn' => $this->getUrn()
        ]);

        return $this->guid;
    }

    /**
     * Loads attributes from the entities table into the object.
     *
     * @param mixed $guid GUID of entity or stdClass object from entities table
     *
     * @return bool
     */
    protected function load($guid)
    {
        if ($guid instanceof stdClass) {
            $row = $guid;
        } else {
            $row = get_entity_as_row($guid);
        }

        if ($row) {
            // Create the array if necessary - all subclasses should test before creating
            if (!is_array($this->attributes)) {
                $this->attributes = [];
            }

            // Now put these into the attributes array as core values
            $objarray = (array) $row;
            foreach ($objarray as $key => $value) {
                $this->attributes[$key] = $value;
            }

            // Increment the portion counter
            if (!$this->isFullyLoaded()) {
                $this->attributes['tables_loaded']++;
            }

            // guid needs to be an int  http://trac.elgg.org/ticket/4111
            $this->attributes['guid'] = (int)$this->attributes['guid'];

            // Cache object handle
            if ($this->attributes['guid']) {
                cache_entity($this);
            }

            return true;
        }

        return false;
    }

    /**
     * Disable this entity.
     *
     * Disabled entities are not returned by getter functions.
     * To enable an entity, use {@link enable_entity()}.
     *
     * Recursively disabling an entity will disable all entities
     * owned or contained by the parent entity.
     *
     * @internal Disabling an entity sets the 'enabled' column to 'no'.
     *
     * @param string $reason    Optional reason
     * @param bool   $recursive Recursively disable all contained entities?
     *
     * @return bool
     * @see enable_entity()
     * @see ElggEntity::enable()
     */
    public function disable($reason = "", $recursive = true)
    {
        if ($r = disable_entity($this->get('guid'), $reason, $recursive)) {
            $this->attributes['enabled'] = 'no';
        }

        return $r;
    }

    /**
     * Enable an entity
     *
     * @warning Disabled entities can't be loaded unless
     * {@link access_show_hidden_entities(true)} has been called.
     *
     * @see enable_entity()
     * @see access_show_hiden_entities()
     * @return bool
     */
    public function enable()
    {
        if ($r = enable_entity($this->get('guid'))) {
            $this->attributes['enabled'] = 'yes';
        }

        return $r;
    }

    /**
     * Is this entity enabled?
     *
     * @return boolean
     */
    public function isEnabled()
    {
        if ($this->enabled == 'yes') {
            return true;
        }

        return false;
    }

    /**
     * Delete this entity.
     *
     * @return bool
     */
    public function delete()
    {
        global $CONFIG, $ENTITY_CACHE;

        //some plugins may want to halt the delete...
        $delete = Minds\Core\Events\Dispatcher::trigger('delete', $this->type, [ 'entity' => $this ]);

        if ($delete && $this->canEdit()) {

            // delete cache
            if (isset($ENTITY_CACHE[$this->guid])) {
                invalidate_cache_for_entity($this->guid);
            }

            // If memcache is available then delete this entry from the cache
            if (is_memcache_available()) {
                $memcache = new ElggMemcache('new_entity_cache');
                $memcache->delete($this->guid);
            }

            // Now delete the entity itself
            $db = new Minds\Core\Data\Call('entities');
            $res = $db->removeRow($this->guid);


            $db = new Minds\Core\Data\Call('entities_by_time');
            foreach ($this->getIndexKeys() as $rowkey) {
                $db->removeAttributes($rowkey, [$this->guid], false);
            }

            \Minds\Core\Events\Dispatcher::trigger('entities-ops', 'delete', [
                'entityUrn' => $this->getUrn()
            ]);

            return true;
        }

        return false;
    }

    /**
     * Returns an array of indexes into which this entity is stored
     *
     * @param bool $ia - ignore access
     * @return array
     */
    protected function getIndexKeys($ia = false)
    {
        //remove from the various lines
        if ($this->access_id == ACCESS_PUBLIC || $ia) {
            $indexes = [
                $this->type,
                "$this->type:$this->subtype"
            ];

            if ($this->super_subtype) {
                array_push($indexes, "$this->type:$this->super_subtype");
            }
        } else {
            $indexes = [];
        }

        if (!$this->hidden) {
            array_push($indexes, "$this->type:$this->super_subtype:user:$this->owner_guid");
            array_push($indexes, "$this->type:$this->subtype:user:$this->owner_guid");
        } else {
            array_push($indexes, "$this->type:$this->super_subtype:user:$this->owner_guid:hidden");
            array_push($indexes, "$this->type:$this->subtype:user:$this->owner_guid:hidden");
        }

        array_push($indexes, "$this->type:container:$this->container_guid");
        array_push($indexes, "$this->type:$this->subtype:container:$this->container_guid");


        return $indexes;
    }

    /*
     * LOCATABLE INTERFACE
     */

    /**
     * Gets the 'location' metadata for the entity
     *
     * @return string The location
     */
    public function getLocation()
    {
        return $this->location;
    }

    /**
     * Sets the 'location' metadata for the entity
     *
     * @todo Unimplemented
     *
     * @param string $location String representation of the location
     *
     * @return bool
     */
    public function setLocation($location)
    {
        $this->location = $location;
        return true;
    }

    /**
     * Set latitude and longitude metadata tags for a given entity.
     *
     * @param float $lat  Latitude
     * @param float $long Longitude
     *
     * @return bool
     * @todo Unimplemented
     */
    public function setLatLong($lat, $long)
    {
        $this->set('geo:lat', $lat);
        $this->set('geo:long', $long);

        return true;
    }

    /**
     * Return the entity's latitude.
     *
     * @return float
     * @todo Unimplemented
     */
    public function getLatitude()
    {
        return (float)$this->get('geo:lat');
    }

    /**
     * Return the entity's longitude
     *
     * @return float
     */
    public function getLongitude()
    {
        return (float)$this->get('geo:long');
    }

    /*
     * EXPORTABLE INTERFACE
     */

    /**
     * Returns an array of fields which can be exported.
     *
     * @return array
     */
    public function getExportableValues()
    {
        return [
            'guid',
            'type',
            'subtype',
            'time_created',
            'time_updated',
            'container_guid',
            'owner_guid',
            'site_guid',
            'access_id',
            'tags',
            'nsfw',
            'nsfw_lock',
            'allow_comments'
        ];
    }

    public function export()
    {
        $export = [];
        foreach ($this->getExportableValues() as $v) {
            if (!is_null($this->$v)) {
                $export[$v] = $this->$v;
            }
        }
        $export = array_merge($export, \Minds\Core\Events\Dispatcher::trigger('export:extender', 'all', ['entity'=>$this], []) ?: []);
        $export = \Minds\Helpers\Export::sanitize($export);
        $export['nsfw'] = $this->getNsfw();
        $export['nsfw_lock'] = $this->getNsfwLock();
        $export['urn'] = $this->getUrn();
        $export['allow_comments'] = $this->getAllowComments();

        if (isset($export['title'])) {
            $export['title'] = (new TitleLengthValidator())->validateMaxAndTrim($export['title']);
        }
        if (isset($export['message'])) {
            $export['message'] = (new MessageLengthValidator())->validateMaxAndTrim($export['message']);
        }

        return $export;
    }

    public function context($context = 'default')
    {
        $this->_context = $context;
    }

    public function getContext()
    {
        return $this->_context;
    }

    public function isContext($context)
    {
        return $this->getContext() == $context;
    }

    /*
     * IMPORTABLE INTERFACE
     */

    /**
     * Import data from an parsed ODD xml data array.
     *
     * @param ODD $data XML data
     *
     * @return true
     *
     * @throws InvalidParameterException
     */
    public function import(ODD $data)
    {
        if (!($data instanceof ODDEntity)) {
            throw new InvalidParameterException(elgg_echo('InvalidParameterException:UnexpectedODDClass'));
        }

        // Set type and subtype
        $this->attributes['type'] = $data->getAttribute('class');
        $this->attributes['subtype'] = $data->getAttribute('subclass');

        // Set owner
        $this->attributes['owner_guid'] = elgg_get_logged_in_user_guid(); // Import as belonging to importer.

        // Set time
        $this->attributes['time_created'] = strtotime($data->getAttribute('published'));
        $this->attributes['time_updated'] = time();

        return true;
    }

    /*
     * SYSTEM LOG INTERFACE
     */

    /**
     * Return an identification for the object for storage in the system log.
     * This id must be an integer.
     *
     * @return int
     */
    public function getSystemLogID()
    {
        return $this->getGUID();
    }

    /**
     * For a given ID, return the object associated with it.
     * This is used by the river functionality primarily.
     *
     * This is useful for checking access permissions etc on objects.
     *
     * @param int $id GUID.
     *
     * @todo How is this any different or more useful than get_entity($guid)
     * or new ElggEntity($guid)?
     *
     * @return int GUID
     */
    public function getObjectFromID($id)
    {
        return get_entity($id);
    }

    /**
     * @return array
     */
    public function getTags()
    {
        // There is a bug on mobile that is sending the hashtags with the hash, this shouldnt happen
        $this->tags = array_map(function ($tag) {
            return (string) str_replace('#', '', $tag);
        }, $this->tags ?: []);
        return $this->tags ?: [];
    }

    /**
     * @param array $value
     * @return $this
     */
    public function setTags(array $value)
    {
        $this->tags = $value;
        return $this;
    }

    /**
     * Feature
     *
     * @return int $guid
     */
    public function feature()
    {
        $db = new Minds\Core\Data\Call('entities_by_time');

        $this->featured_id = Minds\Core\Guid::build();

        $db->insert($this->type.':featured', [$this->featured_id => $this->getGUID()]);
        $db->insert($this->type. ':'.$this->subtype.':featured', [$this->featured_id => $this->getGUID()]);
        if (in_array($this->subtype, ['video', 'image', 'album', 'audio'], true)) {
            $db->insert('object:archive:featured', [$this->featured_id => $this->guid]);
        }

        $this->featured = 1;
        $this->save();

        return $this->featured_id;
    }

    /**
     * Unfeature
     *
     * @return bool
     */
    public function unFeature()
    {
        $db = new Minds\Core\Data\Call('entities_by_time');

        if ($this->featured_id) {
            //supports legacy imports
            $db->removeAttributes("$this->type:featured", [$this->featured_id]);
            $db->removeAttributes("$this->type:$this->subtype:featured", [$this->featured_id]);
            $this->featured_id = null;
        }

        $this->featured = 0;
        $this->save();

        $db = new Minds\Core\Data\Call('entities');
        $result = $db->removeAttributes($this->guid, ['featured_id']);

        return true;
    }

    public function getRating()
    {
        $this->rating = (int) $this->rating;
        return $this->rating === 0 ? 1 : $this->rating;
    }

    public function setRating($value)
    {
        if ($value < 1 && $value > 3) {
            $this->rating = 1;
        }
        $this->rating = $value;
        return $this;
    }

    /**
     * Get NSFW options
     * @return array
     */
    public function getNsfw()
    {
        $array = [];
        if (!$this->nsfw) {
            return $array;
        }
        foreach ($this->nsfw as $reason) {
            $array[] = (int) $reason;
        }
        return $array;
    }

    /**
     * Set NSFW tags
     * @param array $array
     * @return $this
     */
    public function setNsfw($array)
    {
        $array = array_unique($array);
        foreach ($array as $reason) {
            if ($reason < 1 || $reason > 6) {
                throw new \Exception('Incorrect NSFW value provided');
            }
        }
        
        $this->nsfw = $array;
        return $this;
    }

    /**
    * Get NSFW Lock options.
    *
    * @return array
    */
    public function getNsfwLock()
    {
        $array = [];
        if (!$this->nsfwLock) {
            return $array;
        }
        foreach ($this->nsfwLock as $reason) {
            $array[] = (int) $reason;
        }

        return $array;
    }
    
    /**
     * Set NSFW lock tags for administrators. Users cannot remove these themselves.
     *
     * @param array $array
     *
     * @return $this
     */
    public function setNsfwLock($array)
    {
        $array = array_unique($array);
        foreach ($array as $reason) {
            if ($reason < 1 || $reason > 6) {
                throw \Exception('Incorrect NSFW value provided');
            }
        }
        $this->nsfwLock = $array;

        return $this;
    }

    /**
     * Return a preferred urn
     * @return string
     */
    public function getUrn(): string
    {
        return "urn:entity:{$this->getGuid()}";
    }

    /** gets the guid of the moderator
     * @return int
     */
    public function getModeratorGuid()
    {
        return $this->moderator_guid;
    }


    /**
     * Marks the user as moderated by a user
     * @param int $moderatorGuid the moderator
     */
    public function setModeratorGuid(int $moderatorGuid)
    {
        $this->moderator_guid = $moderatorGuid;
    }

    /**
     * Marks the time as when an entity was moderated
     * @param int $timeModerated unix timestamp when the entity was moderated
     */
    public function setTimeModerated(int $timeModerated)
    {
        $this->time_moderated = $timeModerated;
    }
    
    /**
     * Gets the time moderated
     * @return int
     */
    public function getTimeModerated()
    {
        return $this->time_moderated;
    }

    /**
     * Sets the flag for allowing comments on an entity
     * @param bool $allowComments
     */
    public function setAllowComments(bool $allowComments)
    {
        $this->allow_comments = $allowComments;
        return $this;
    }

    /**
     * Gets the flag for allowing comments on an entity
     */
    public function getAllowComments()
    {
        return (bool) $this->allow_comments;
    }

    /**
     * Set the source if externally imported
     * @param string $source - eg. nostr
     * @return self
     */
    public function setSource(string $source): self
    {
        $this->source = $source;
        return $this;
    }

    /**
     * Returns the origin/source, eg. nostr
     * @return string
     */
    public function getSource(): string
    {
        return $this->source;
    }
}
