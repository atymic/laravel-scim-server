<?php

namespace ArieTimmerman\Laravel\SCIMServer\Attribute;

use Illuminate\Support\Carbon;
use ArieTimmerman\Laravel\SCIMServer\Exceptions\SCIMException;
use ArieTimmerman\Laravel\SCIMServer\SCIM\Schema;
use ArieTimmerman\Laravel\SCIMServer\Helper;

class AttributeMapping {
	
	public $read, $add, $replace, $remove, $writeAfter;
	
	public $id = null;
	public $parent = null;
	public $filter = null;
	
	public $key = null;
	
	private $readEnabled = true;
	private $writeEnabled = true;
	
	private $sortAttribute = null;
	
	private $mappingAssocArray = null;
	
	public $eloquentAttributes = [];
	
	private $defaultSchema = null, $schema = null;

	/**
	 * Can be always, never, default, request
	 */
	private $returned = 'always';

	public const RETURNED_ALWAYS = 'always';
	public const RETURNED_NEVER = 'never';
	public const RETURNED_DEFAULT = 'default';
	public const RETURNED_REQUEST = 'request';
	
	public static function noMapping($parent = null) : AttributeMapping{
	    return (new AttributeMapping())->disableWrite()->ignoreRead()->setParent($parent);
	}
	
	public static function arrayOfObjects($mapping, $parent = null) : AttributeMapping{
	    return (new Collection())->setStaticCollection($mapping)->setRead(
	       function (&$object) use ($mapping, $parent){
	           
	           $result = [];
	           
	           foreach($mapping as $key=>$o){
	               $element = self::ensureAttributeMappingObject($o)->setParent($parent)->read($object);
	               
	               if($element != null){
	                   $result[] = $element;
	               }
	           }
	           
	           return empty($result) ? null : $result;
	           
	       }
	    );
	}
	
	public static function object($mapping, $parent = null) : AttributeMapping{
	    
	    return (new AttributeMapping())->setMappingAssocArray($mapping)->setRead(function(&$object) use ($mapping, $parent) {
	        	        
		    $result = [];
		    
		    foreach($mapping as $key=>$value){
		        
                $result[$key] = self::ensureAttributeMappingObject($value)->setParent($parent)->read($object);
                
                if(empty($result[$key]) && !is_bool($result[$key])){
                    unset($result[$key]);
                }
		    }
		    
		    return empty($result) ? null : $result;
		    
		});
	}
	
	public static function constant($text, $parent = null) : AttributeMapping{
	    return (new AttributeMapping())->disableWrite()->setParent($parent)->setRead(function(&$object) use ($text) {
		    
		    return $text;
		    
		});
	    
	}
	
	public static function eloquent($eloquentAttribute, $parent = null) : AttributeMapping{
	    
	    return (new AttributeMapping())->setParent($parent)->setRead(function(&$object) use ($eloquentAttribute)  {
	        	        
			    $result = $object->{$eloquentAttribute};
			    
			    return self::eloquentAttributeToString($result); 
			    
			})->setAdd(function($value, &$object) use ($eloquentAttribute) {
			    $object->{$eloquentAttribute} = $value; 
			})->setReplace(function($value, &$object) use ($eloquentAttribute) {
			    $object->{$eloquentAttribute} = $value; 
			})->setSortAttribute($eloquentAttribute)->setEloquentAttributes([$eloquentAttribute]);
	}

	public static function eloquentCollection($eloquentAttribute, $parent = null) : AttributeMapping{
	    
	    return (new AttributeMapping())->setParent($parent)->setRead(function(&$object) use ($eloquentAttribute)  {
	        	        
				$result = $object->{$eloquentAttribute};
							    
			    return self::eloquentAttributeToString($result); 
			    
			})->setAdd(function($value, &$object) use ($eloquentAttribute) {
				
				if(!is_array($value)){
					$value = [$value];
				}

				$object->{$eloquentAttribute}()->attach(collect($value)->pluck('value'));

			})->setReplace(function($value, &$object) use ($eloquentAttribute) {

				$object->{$eloquentAttribute}()->sync(collect($value)->pluck('value'));


			})->setSortAttribute($eloquentAttribute)->setEloquentAttributes([$eloquentAttribute]);
	}
	
	public function setMappingAssocArray($mapping) : AttributeMapping{
	    $this->mappingAssocArray = $mapping;
	    
	    return $this;
	}
	
	public function setSchema($schema) : AttributeMapping{
	    $this->schema = $schema;
	    return $this;
	}
	
	public function getSchema(){
	    return $this->schema;
	}
	
	public function setDefaultSchema($schema) : AttributeMapping{
	    $this->defaultSchema = $schema;
	    
	    return $this;
	}
	
	public function getDefaultSchema(){
	    return $this->defaultSchema;
	}
	
	public function setEloquentAttributes(array $attributes){
	    $this->eloquentAttributes = $attributes;
	    
	    return $this;
	}
	
	public function getEloquentAttributes(){
	    
	    $result = $this->eloquentAttributes;
	    
	    if($this->mappingAssocArray){
	        foreach($this->mappingAssocArray as $key=>$value){
	            foreach(self::ensureAttributeMappingObject($value)->setParent($this)->getEloquentAttributes() as $attribute){
	                $result[] = $attribute;
	            }
	        }
	    }
	    
	    return $result;
	}
	
	public function disableRead(){
	    $parent = $this;
	    
	    $this->read = function(&$object) use ($parent){ 
	       // throw new SCIMException('Read is not supported for ' . $parent->getFullKey());
	       return null; //"disabled!!";
	    };
	    
	    $this->readEnabled = false;
	    
	    return $this;
	}
	
	public function ignoreRead(){
	   
	    $this->read = function(&$object){
	        return null;
	    };
	     
	    return $this;
	    
	}
	
	public function ignoreWrite(){
	    
	    $ignore = function($value, &$object){
	        //ignore
	    };
	    
	    $this->add = $ignore;
	    $this->replace = $ignore;
	    $this->remove = $ignore;
	    
	    return $this;
	    
	}
	
	public function disableWrite(){
	    
        $disable = function($value, &$object){ 
            throw (new SCIMException(sprintf('Write to "%s" is not supported',$this->getFullKey())))->setCode(500)->setScimType('mutability');
        };
        
        $this->add = $disable;
        $this->replace = $disable;
        $this->remove = $disable;
        
        $this->writeEnabled = false;
        
	    return $this;
	}
	
	public function setRead($read) : AttributeMapping{
	    
	    $this->read = $read;
	    
	    return $this;
	}
	
	public function setAdd($write){
	     
	    $this->add = $write;
	     
	    return $this;
	}

	public function setReturned($returned){
	     
	    $this->returned = $returned;
	     
	    return $this;
	}
	
	public function setReplace($replace){
	     
	    $this->replace = $replace;
	     
	    return $this;
	}

	public function setWriteAfter($write){
	    $this->writeAfter = $writeAfter;
	    
	    return $this;
	}
	
	public function setParent($parent){
	    $this->parent = $parent;
	    return $this;
	}
	
	public function setKey($key){
	    $this->key = $key;
	    return $this;
	}
	
	public function getKey(){
	    return $this->key;
	}
	
	public function getFullKey(){
	    
	    $parent = $this->parent;
	    
	    $fullKey = [];
	    
	    while($parent != null){	        
	        array_unshift($fullKey, $parent->getKey());
	        $parent = $parent->parent;
	    }
	    
	    $fullKey[]  = $this->getKey();
	    
	    //ugly hack
	    $fullKey = array_filter($fullKey, function($value){ return !empty($value); });
	    
	    return Helper::getFlattenKey($fullKey, [$this->getSchema() ?? $this->getDefaultSchema()]);
	}
	
	public function setFilter($filter){
	    
	    $this->filter = $filter;
	    
	    return $this;
	}
	
	function __construct() {
		
	    $this->read = function($object){
	        throw new SCIMException(sprintf('Read is not implemented for "%s"',$this->getFullKey()));
	    };
	    
	    $this->writeAfter = function($value, &$object){
	        
	    };
	    
	    $this->add = function($value, &$object){
	        throw new SCIMException(sprintf('Write is not implemented for "%s"',$this->getFullKey()));
	    };
	    
	    $this->replace = function($value, &$object){
	        throw new SCIMException(sprintf('Replace is not implemented for "%s"',$this->getFullKey()));
	    };
	    
	    $this->remove = function($value, &$object){
	        $this->add(null,$object);
	        //throw new SCIMException(sprintf('Remove is not implemented for "%s"',$this->getFullKey()));
	    };
		
	}
	
	public function setSortAttribute($attribute){
	    $this->sortAttribute = $attribute;
	    
	    return $this;
	}
	
	public function getSortAttribute(){
	    
	    if(!$this->readEnabled){
	        throw new SCIMException(sprintf('Can\'t sort on unreadable attribute "%s"',$this->getFullKey()) );
	    }
	    
	    return $this->sortAttribute;
	}
	
	public function withFilter($filter){
	    
        return $this->setFilter($filter);
	    
	}
	
	public function add($value, &$object) {
	    return ($this->add)($value, $object);
	}
	
	public function replace($value, &$object) {
	    
	    $current = $this->read($object);
	    
	    //TODO: Really implement replace ...???	    
	    return ($this->replace)($value, $object);
	    
	}
	
	public function remove(&$object) {
	    
	    //TODO: implement remove for multi valued attributes 
	    return ($this->remove)(null, $object);
	    
	}
	
	public function writeAfter($value, &$object) {
	    
        return ($this->writeAfter)($value, $object);
	    
	}
	
	public function read(&$object) {
		return ($this->read)($object);
	}	
	
	public static function eloquentAttributeToString($value){
	    
	    if($value instanceof \Carbon\Carbon){
	        $value = $value->format('c');
	    }
	    
	    return $value;
	}
	
	public function isReadSupported(){
	    return $this->readEnabled;
	}
	
	public function isWriteSupported(){
	    return $this->writeEnabled;
	}
	
	public static function ensureAttributeMappingObject($attributeMapping, $parent = null) : AttributeMapping{
	    
	   $result = null;
	   
	   if($attributeMapping == null){
            $result = self::noMapping($parent);
        }else if (is_array($attributeMapping) && !empty($attributeMapping) && isset($attributeMapping[0]) ){
            $result = self::arrayOfObjects($attributeMapping, $parent);
        }else if(is_array($attributeMapping)){
            $result = self::object($attributeMapping, $parent);
        }else if ($attributeMapping instanceof AttributeMapping){
            $result = $attributeMapping->setParent($parent);
        }else{
            throw (new SCIMException(sprintf('Found unknown attribute "%s" in "%s"',$attributeMapping,$this->getFullKey())))->setCode(500);
        }
        
        return $result;
	    
	}
	
	/**
	 * Returns the AttributeMapping for a specific value. Uses for example for creating queries ... and sorting
	 * @param unknown $value
	 * @return \ArieTimmerman\Laravel\SCIMServer\Attribute\AttributeMapping
	 */
	public function getSubNode($key, $schema = null){
	     
	    if($key == null) return $this;    
	    
	    if($this->mappingAssocArray != null && array_key_exists($key,$this->mappingAssocArray)){
	        return self::ensureAttributeMappingObject($this->mappingAssocArray[$key])->setParent($this)->setKey($key)->setSchema($schema);
	    }else{
	        throw new SCIMException(sprintf('No mapping for "%s" in "%s"',$key,$this->getFullKey()));
	    }
	     
	}
	
	public function getNode($attributePath){
	    
	    if( empty($attributePath) ){
	        return $this;
		}
		
		//The first schema should be the default one
		$schema = $attributePath->schema ?? $this->getDefaultSchema()[0];
	    	    
	    if(!empty($schema) && !empty($this->getSchema()) && $this->getSchema() != $schema){
	        throw (new SCIMException(sprintf('Trying to get attribute for schema "%s". But schema is already "%s"',$attributePath->schema,$this->getSchema())))->setCode(500)->setScimType('noTarget');
	    }
	    
	    $elements = [];
	    
	    if(empty($attributePath->attributeNames) && !empty($schema)){
	        $elements[] = $schema;
	    }else if(empty($this->getSchema()) && !in_array($attributePath->attributeNames[0],Schema::ATTRIBUTES_CORE) ){
	        $elements[] = $schema ?? (is_array($this->getDefaultSchema()) ? $this->getDefaultSchema()[0] : $this->getDefaultSchema());
		}
	    
	    foreach($attributePath->attributeNames as $a){
	        $elements[] = $a;
	    }
	    
	    /** @var AttributeMapping */
	    $node = $this;
	    
        foreach($elements as $element){
			try{
				$node = $node->getSubNode($element, $schema);
			}catch(\Exception $e){
				throw $e;
			}
        }
        
        return $node;	    
	    
	}
	
	public function getSubNodeWithPath($path){
	    
	    if($path == null){
	        return $this;
	    }else{
	        
	        $getAttributePath = function() {
	            return $this->attributePath;
	        };
	
	        $getValuePath = function() {
	            return $this->valuePath;
	        };
	
	        $getFilter = function() {
	            return $this->filter;
	        };
			
			$first = @$getAttributePath->call($getValuePath->call($path));
			$filter = @$getFilter->call($getValuePath->call($path));
			$last = $getAttributePath->call($path);
			
			return $this->getNode( $first )->withFilter( $filter  )->getNode( $last );
	
	    }
	
	    return $result;
	}
	
	public function applyWhereCondition(&$query,$operator,$value){
	    
	    //only filter on OWN eloquent attributes
	    if(empty($this->eloquentAttributes)) throw new SCIMException("Can't filter on . " + $this->getFullKey());
	    
	    $attribute = $this->eloquentAttributes[0];
	    
	    switch($operator){
	         
	        case "eq":
	            $query->where($attribute,$value);
	            break;
	        case "ne":
	            $query->where($attribute,'<>',$value);
	            break;
	        case "co":
	            $query->where($attribute,'like','%' . addcslashes($value, '%_') . '%');
	            break;
	        case "sw":
	            $query->where($attribute,'like',addcslashes($value, '%_') . '%');
	            break;
	        case "ew":
	            $query->where($attribute,'like','%' . addcslashes($value, '%_'));
	            break;
	        case "pr":
	            $query->whereNotNull($attribute);	    
	            break;
	        case "gt":
	            $query->where($attribute,'>',$value);
	            break;
	        case "ge":
	            $query->where($attribute,'>=',$value);
	            break;
	        case "lt":
	            $query->where($attribute,'<',$value);
	            break;
	        case "le":
	            $query->where($attribute,'<=',$value);
	            break;
	        default:
	            die("Not supported!!");
	            break;
	            
	    }
	    
	}
	
}