<?php
class TinyActiveRecord{
  
  protected $obj_attributes;
  protected static $class_attributes;
  protected static $table;
  
  public function __construct($att = array()){
    $types = static::getAttributeTypes();
    foreach($att as $column => $value){
      if(preg_match("/^[a-zA-Z0-9_-]+$/", $column)){
        if(in_array($types[$column], array('datetime', 'timestamp')) && preg_match('/^\d+$/', $value)){
          $date = new DateTime();
          $date->setTimestamp($value);
          $this->$column = $date;
        }
        else
          $this->$column = $value;
      }
    }
    if(!static::$class_attributes){
      static::$class_attributes = array('attributes' => null, 'default_values' => null, 'attribute_types' => null);
      static::getAttributes();
    }
    $this->obj_attributes = array('_created' => false, '_saved' => false);
  }
  
  public static function withId($id){
    if(!preg_match("#^[0-9]+$#", $id))
      throw new Exception('This is not an ID');
    
    $stmt = self::selectQuery(array("id=?", $id));
    
    return self::constructObj($stmt);
  }
  
  
  public static function find($id){
    return self::withId($id);
  }
  
  public function update($att = array()){
    foreach($att as $column => $value){
      if(preg_match("/^[a-zA-Z0-9_-]+$/", $column))
        $this->$column = $value;
    }
    $this->setSaved(false);
    return $this;
  }
  
  public function isCreated(){
    return (isset($this->obj_attributes['_created']) ? $this->obj_attributes['_created'] : false);
  }
  
  public function setCreated($val){
    $this->obj_attributes['_created'] = $val;
  }
  
  public function isSaved(){
    return (isset($this->obj_attributes['_saved']) ? $this->obj_attributes['_saved'] : false);
  }
  
  public function setSaved($val){
    $this->obj_attributes['_saved'] = $val;
  }
  
  public function save($force = false){
    // use that when once we added to set to false saved flag when we use 
    //if($this->isSaved() && !$force)
    //  return true;
    // insert it if not created
    if(!$this->isCreated()){
      $col_list = array();
      $skip_cols = array('id', 'updated_at');
      foreach(self::getAttributes() as $col){
        if(!in_array($col, $skip_cols)){
          $col_list[] = $col;
        }
      }
      
      try{
        $stmt = DB2::getCnx()->prepare("INSERT INTO ".static::$table." (`".implode("`,`", $col_list)."`) VALUES (".implode(",", array_fill(0, sizeof($col_list), '?')).")");
        $values = array();
        $defaults = static::getDefaultValues();
        $types = static::getAttributeTypes();
        foreach(self::getAttributes() as $col){
          if($col == "created_at")
            $values[] = strftime("%F %T");
          else if(!in_array($col, $skip_cols)){
            if(in_array($types[$col], array('datetime', 'timestamp')))
              $values[] = isset($this->$col) ? strftime("%F %T", $this->col) : (isset($defaults[$col]) ? $defaults[$col] : null);
            else
              $values[] = (isset($this->$col) ? $this->$col : (isset($defaults[$col]) ? $defaults[$col] : null));
          }
        }
        $stmt->execute($values);
      }
      catch(Exception $e){
        Logger::log("Erreur SQL Inserting ".get_class($this)." ID ".$this->getId());
        Logger::log($e->getMessage());
        return false;
      }
      finally{
        $stmt->closeCursor();
      }
      
      $this->id = DB2::getCnx()->lastInsertId();
      $this->setCreated(true);
      $this->setSaved(true);
      return true;
    }
    //update it if created
    else{
      $col_list = array();
      $skip_cols = array('id', 'updated_at', 'created_at');
      foreach(self::getAttributes() as $col){
        if(!in_array($col, $skip_cols)){
          $col_list[] = $col."= ?";
        }
      }
      
      try{
        $stmt = DB2::getCnx()->prepare("UPDATE ".static::$table." SET ".implode(", ", $col_list)." WHERE id=?");
        $values = array();
        $types = static::getAttributeTypes();
        foreach(self::getAttributes() as $col){
          if(!in_array($col, $skip_cols)){
            if(in_array($types[$col], array('datetime', 'timestamp')))
              $values[] = isset($this->$col) ? (get_class($this->$col) == 'DateTime' ? $this->$col->format('Y-m-d H-i-s') : strftime('%F %T', $this->$col)) : null;
            else
              $values[] = ($this->$col != "" ? $this->$col : null);
          }
        }
        $values[] = $this->id;
        $stmt->execute($values);
      }
      catch(Exception $e){
        Logger::log("Erreur SQL Updating ".__CLASS__." ID ".$this->getId());
        Logger::log($e->getMessage());
        return false;
      }
      finally{
        $stmt->closeCursor();
      }
      
      $this->setSaved(true);
      return true;
    }
  }
  
  public function getId(){
    return $this->id;
  }
  
  public function getCreatedAt(){
    return $this->created_at;
  }
  
  public function getFormattedCreatedAt(){
    return ($this->getCreatedAt() ? $this->getCreatedAt()->format('d/m/Y à H\hi') : "N/A");
  }
  
  public function getUpdatedAt(){
    return $this->updated_at;
  }
  
  public function getFormattedUpdatedAt(){
    return ($this->getUpdatedAt() ? $this->getUpdatedAt()->format('d/m/Y à H\hi') : "N/A");
  }
  
  public function delete(){
    try{
      $stmt = DB2::getCnx()->prepare("DELETE FROM ".static::$table." WHERE id=?");
      $values = array();
      $values[] = $this->id;
      $stmt->execute($values);
    }
    catch(Exception $e){
      Logger::log("Erreur SQL Deleting ".get_clas($this)." ID ".$this->getId());
      Logger::log($e->getMessage());
      return false;
    }
    finally{
      $stmt->closeCursor();
    }
    
    return true;
  }
  
  public function __call($name, $arguments){
    if(0 === strpos($name, 'set')){
      $name = preg_replace('/^set/', '', $name);
      $property = Naming::underscore($name);
      if(!in_array($property, self::getAttributes()))
        throw new Exception("No property named $property for ".get_class($this)." kind of object");
      $this->$property = $arguments[0];
      $this->setSaved(false);
      return $this;
    }
    if(0 === strpos($name, 'get')){
      $name = preg_replace('/^get/', '', $name);
      $property = Naming::underscore($name);
      if(!in_array($property, self::getAttributes()))
        throw new Exception("No property named $property for ".get_class($this)." kind of object");
      return $this->$property;
    }
    if(0 === strpos($name, 'is')){
      $name = preg_replace('/^is/', '', $name);
      $property = Naming::underscore($name);
      if(!in_array($property, self::getAttributes()))
        throw new Exception("No property named $property for ".get_class($this)." kind of object");
      return $this->$property === "1";
    }
    throw new Exception("No method named $name for ".get_class($this)." kind of object");
  }
  
  private static function setAttributesDefaultsAndTypes(){
    $attributes = array();
    $default_values = array();
    $attribute_types = array();
    try{
      $stmt = DB2::getCnx()->query("SHOW COLUMNS FROM ".static::$table, PDO::FETCH_ASSOC);
      while($r = $stmt->fetch()){
        $attributes[] = $r["Field"];
        $default_values[$r["Field"]] = $r["Default"];
        $attribute_types[$r["Field"]] = $r["Type"];
      }
    }
    catch(Exception $e){
      Logger::log($e->getMessage());
    }
    static::$class_attributes['attributes'] = $attributes;
    static::$class_attributes['default_values'] = $default_values;
    static::$class_attributes['attribute_types'] = $attribute_types;
    
  }
  
  public static function getAttributes(){
    if(!static::$class_attributes['attributes']){
      static::setAttributesDefaultsAndTypes();
    }
    return static::$class_attributes['attributes'];
  }
  
  public static function getDefaultValues(){
    if(!static::$class_attributes['default_values']){
      static::setAttributesDefaultsAndTypes();
    }
    return static::$class_attributes['default_values'];
  }
  
  public static function getAttributeTypes(){
    if(!static::$class_attributes['attribute_types']){
      static::setAttributesDefaultsAndTypes();
    }
    return static::$class_attributes['attribute_types'];
  }
  
  public static function permit_params($table, $permited_params){
    $values = array();
    foreach($_POST[$table] as $key => $val){
      if(in_array($key, $permited_params))
        $values[$key] = $val;
    }
    return $values;
  }
  
  // @param $where [Array] array with 0 => where query with ? as values, 1.. => values
  protected static function selectQuery($where){
    $col_list = array();
    $types = static::getAttributeTypes();
    foreach(self::getAttributes() as $col){
      if(in_array($types[$col], array('datetime', 'timestamp')))
        $col_list[] = "UNIX_TIMESTAMP(`$col`) AS $col";
      else
        $col_list[] = "`$col`";
    }
    try{
      $stmt = DB2::getCnx()->prepare("SELECT ".implode(',', $col_list)." FROM ".static::$table." WHERE ".array_shift($where)." LIMIT 1");
      $stmt->execute($where);
    }
    catch(Exception $e){
      Logger::log($e->getMessage());
      return false;
    } 
    return $stmt;
  }
  
  protected static function constructObj($stmt){
    if(!$stmt)
      exit("Erreur");
    if($stmt->rowCount() <= 0)
      return null;
    
    try{
      $r = $stmt->fetch(PDO::FETCH_ASSOC);
    }    
    catch(Exception $e){
      Logger::log($e->getMessage());
      return false;
    }
    finally{
      $stmt->closeCursor();
    }
    
    $obj = new static($r);
    $obj->setCreated(true);
    $obj->setSaved(true);
    return $obj;
  }
}

?>