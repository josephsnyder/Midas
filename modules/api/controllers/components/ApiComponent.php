<?php
/*=========================================================================
MIDAS Server
Copyright (c) Kitware SAS. 20 rue de la Villette. All rights reserved.
69328 Lyon, FRANCE.

See Copyright.txt for details.
This software is distributed WITHOUT ANY WARRANTY; without even
the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR
PURPOSE.  See the above copyright notices for more information.
=========================================================================*/

// Web API error codes
define('MIDAS_INTERNAL_ERROR', -100);
define('MIDAS_INVALID_TOKEN', -101);
define('MIDAS_INVALID_PARAMETER', -150);
define('MIDAS_INVALID_POLICY', -151);
define('MIDAS_HTTP_ERROR', -153);

/** These are the implementations of the core web api methods */
class Api_ApiComponent extends AppComponent
  {

  public $controller;
  public $apiSetup;
  public $userSession;

  /**
   * Pass the args and a list of required parameters.
   * Will throw an exception if a required one is missing.
   */
  private function _validateParams($args, $requiredList)
    {
    foreach($requiredList as $param)
      {
      if(!array_key_exists($param, $args))
        {
        throw new Exception('Parameter '.$param.' is not defined', MIDAS_INVALID_PARAMETER);
        }
      }
    }

  /** Return the user dao */
  private function _getUser($args)
    {
    $componentLoader = new MIDAS_ComponentLoader();
    $authComponent = $componentLoader->loadComponent('Authentication', 'api');
    return $authComponent->getUser($args, $this->userSession->Dao);
    }

  /**
   * Get the server version
   * @return Server version
   */
  public function version($args)
    {
    $data['version'] = Zend_Registry::get('configDatabase')->version;
    return $data;
    }

  /**
   * Get the server information
   * @return Server information
   */
  public function info($args)
    {
    $data['version'] = Zend_Registry::get('configDatabase')->version;
    return $data;
    }

  /**
   * Login as a user using a web api key
   * @param appname The application name
   * @param email The user email
   * @param apikey The api key corresponding to the given application name
   * @return A web api token that will be valid for a set duration
   */
  function login($args)
    {
    $this->_validateParams($args, array('email', 'appname', 'apikey'));

    $data['token'] = '';
    $email = $args['email'];
    $appname = $args['appname'];
    $apikey = $args['apikey'];
    $modelLoader = new MIDAS_ModelLoader();
    $Api_Userapi = $modelLoader->loadModel('Userapi', 'api');
    $tokenDao = $Api_Userapi->getToken($email, $apikey, $appname);
    if(empty($tokenDao))
      {
      throw new Exception('Unable to authenticate.Please check credentials.', MIDAS_INVALID_PARAMETER);
      }
    $data['token'] = $tokenDao->getToken();
    return $data;
    }

  /**
   * Get a resource by its UUID
   * @param uuid Universal identifier for the resource
   * @param folder (Optional) If set, will return the folder instead of the community record
   * @return The resource's dao
   */
  function resourceGet($args)
    {
    $this->_validateParams($args, array('uuid'));

    $uuid = $args['uuid'];
    $componentLoader = new MIDAS_ComponentLoader();
    $uuidComponent = $componentLoader->loadComponent('Uuid');
    $resource = $uuidComponent->getByUid($uuid);

    if($resource == false)
      {
      throw new Exception('No resource for the given UUID.', MIDAS_INVALID_PARAMETER);
      }

    if($resource->resourceType == MIDAS_RESOURCE_COMMUNITY && array_key_exists('folder', $args))
      {
      return array('type' => MIDAS_RESOURCE_FOLDER, 'id' => $resource->getFolderId());
      }
    return array('type' => $resource->resourceType, 'id' => $resource->getKey());
    }

  /**
   * Returns a path of uuids from the root folder to the given node
   * @param uuid Unique identifier of the resource
   * @return An ordered list of uuids representing a path from the root node
   */
  function pathFromRoot($args)
    {
    return array_reverse($this->pathToRoot($args));
    }

  /**
   * Returns a path of uuids from the given node to the root node
   * @param uuid Unique identifier of the resource
   * @return An ordered list of uuids representing a path to the root node
   */
  function pathToRoot($args)
    {
    $this->_validateParams($args, array('uuid'));

    $componentLoader = new MIDAS_ComponentLoader();
    $uuidComponent = $componentLoader->loadComponent('Uuid');
    $folder = $uuidComponent->getByUid($args['uuid']);

    $return = array();
    $return[] = $folder->toArray();

    if($folder == false)
      {
      throw new Exception('No resource for the given UUID.', MIDAS_INVALID_PARAMETER);
      }

    if(!$folder instanceof FolderDao)
      {
      throw new Exception('Should be a folder.', MIDAS_INVALID_PARAMETER);
      }

    $parent = $folder->getParent();
    while($parent !== false)
      {
      $return[] = $parent->toArray();
      $parent = $parent->getParent();
      }
    return $return;
    }

  /**
   * Search resources for the given words
   * @param token (Optional) Authentication token
   * @param search The search query
   * @return An array of matching resources
   */
  function resourceSearch($args)
    {
    $this->_validateParams($args, array('search'));
    $userDao = $this->_getUser($args);

    $order = 'view';
    if(isset($args['order']))
      {
      $order = $args['order'];
      }
    $componentLoader = new MIDAS_ComponentLoader();
    $searchComponent = $componentLoader->loadComponent('Search');
    return $searchComponent->searchAll($userDao, $args['search'], $order);
    }

  /**
   * Generate a unique upload token
   * @param token Authentication token
   * @param itemid The id of the parent item to upload into
   * @param filename The filename of the bitstream you will upload
   * @param checksum (Optional) The md5 checksum of the file to be uploaded
   * @return An upload token that can be used to upload a file.
             If checksum is passed and the token returned is blank, the
             server already has this file and there is no need to upload.
   */
  function uploadGeneratetoken($args)
    {
    $this->_validateParams($args, array('itemid', 'filename'));
    $userDao = $this->_getUser($args);
    if(!$userDao)
      {
      throw new Exception('Anonymous users may not upload', MIDAS_INVALID_POLICY);
      }

    $modelLoader = new MIDAS_ModelLoader();
    $itemModel = $modelLoader->loadModel('Item');
    $item = $itemModel->load($args['itemid']);
    if(!$itemModel->policyCheck($item, $userDao, MIDAS_POLICY_WRITE))
      {
      throw new Exception('Invalid policy or itemid', MIDAS_INVALID_POLICY);
      }

    if(array_key_exists('checksum', $args))
      {
      // If we already have a bitstream with this checksum, create a reference and return blank token
      $bitstreamModel = $modelLoader->loadModel('Bitstream');
      $existingBitstream = $bitstreamModel->getByChecksum($args['checksum']);
      if($existingBitstream)
        {
        $revision = $itemModel->getLastRevision($item);

        if($revision == false)
          {
          // Create new revision if none exists yet
          Zend_Loader::loadClass('ItemRevisionDao', BASE_PATH.'/core/models/dao');
          $revision = new ItemRevisionDao();
          $revision->setChanges('Initial revision');
          $revision->setUser_id($userDao->getKey());
          $revision->setDate(date('c'));
          $revision->setLicense(null);
          $revision = $itemModel->addRevision($item, $revision);
          }

        $siblings = $revision->getBitstreams();
        foreach($siblings as $sibling)
          {
          if($sibling->getName() == $args['filename'])
            {
            // already have a file with this name. don't add new record.
            return array('token' => '');
            }
          }
        Zend_Loader::loadClass('BitstreamDao', BASE_PATH.'/core/models/dao');
        $bitstream = new BitstreamDao();
        $bitstream->setChecksum($args['checksum']);
        $bitstream->setName($args['filename']);
        $bitstream->setSizebytes($existingBitstream->getSizebytes());
        $bitstream->setPath($existingBitstream->getPath());
        $bitstream->setAssetstoreId($existingBitstream->getAssetstoreId());
        $bitstream->setMimetype($existingBitstream->getMimetype());
        $revisionModel = $modelLoader->loadModel('ItemRevision');
        $revisionModel->addBitstream($revision, $bitstream);
        return array('token' => '');
        }
      }
    //we don't already have this content, so create the token
    $uploadApi = new KwUploadAPI($this->apiSetup);
    return $uploadApi->generateToken($args, $userDao->getKey().'/'.$item->getKey());
    }

  /**
   * Get the size of a partially completed upload
   * @param uploadtoken The upload token for the file
   * @return [offset] The size of the file currently on the server
   */
  function uploadGetoffset($args)
    {
    $uploadApi = new KwUploadAPI($this->apiSetup);
    return $uploadApi->getOffset($args);
    }

  /**
   * Upload a file to the server. PUT or POST is required. Either the itemid
       or folderid parameter must be set
   * @param uploadtoken The upload token (see upload.generatetoken)
   * @param filename The upload filename
   * @param length The length in bytes of the file being uploaded
   * @param mode (Optional) Stream or multipart. Default is stream
   * @param folderid (Optional) The id of the folder to upload into
   * @param itemid (Optional) If set, will create a new revision in the existing item
   * @param revision (Optional) If set, will add a new file into an existing
            revision. Set this to "head" to add to the most recent revision.
   * @param return The item information of the item created or changed
   */
  function uploadPerform($args)
    {
    $this->_validateParams($args, array('uploadtoken', 'filename', 'length'));
    if(!$this->controller->getRequest()->isPost() && !$this->controller->getRequest()->isPut())
      {
      throw new Exception('POST or PUT method required', MIDAS_HTTP_ERROR);
      }

    list($userid, $resourceid, ) = explode('/', $args['uploadtoken']);

    $modelLoader = new MIDAS_ModelLoader();
    $itemModel = $modelLoader->loadModel('Item');
    $userModel = $modelLoader->loadModel('User');
    $userDao = $userModel->load($userid);
    if(!$userDao)
      {
      throw new Exception('Invalid user id from upload token', MIDAS_INVALID_PARAMETER);
      }

    if(array_key_exists('revision', $args) && array_key_exists('itemid', $args))
      {
      if($args['itemid'] != $resourceid)
        {
        throw new Exception('Upload token does not match itemid', MIDAS_INVALID_PARAMETER);
        }
      $item = $itemModel->load($args['itemid']);
      if($item == false)
        {
        throw new Exception('Unable to find item', MIDAS_INVALID_PARAMETER);
        }
      if(strtolower($args['revision']) == 'head')
        {
        $revision = $itemModel->getLastRevision($item);

        if($revision == false)
          {
          // Create new revision if none exists yet
          Zend_Loader::loadClass('ItemRevisionDao', BASE_PATH.'/core/models/dao');
          $revision = new ItemRevisionDao();
          $revision->setChanges('Initial revision');
          $revision->setUser_id($userDao->getKey());
          $revision->setDate(date('c'));
          $revision->setLicense(null);
          $revision = $itemModel->addRevision($item, $revision);
          }
        }
      else
        {
        $revision = $itemModel->getRevision($item, $args['revision']);
        if($revision == false)
          {
          throw new Exception('Unable to find revision', MIDAS_INVALID_PARAMETER);
          }
        }
      }
    elseif(array_key_exists('itemid', $args))
      {
      if($args['itemid'] != $resourceid)
        {
        throw new Exception('Upload token does not match itemid', MIDAS_INVALID_PARAMETER);
        }
      $item = $itemModel->load($args['itemid']);
      if($item == false)
        {
        throw new Exception('Unable to find item', MIDAS_INVALID_PARAMETER);
        }
      if(!$itemModel->policyCheck($item, $userDao, MIDAS_POLICY_WRITE))
        {
        throw new Exception('Permission error', MIDAS_INVALID_POLICY);
        }
      }
    elseif(array_key_exists('folderid', $args))
      {
      if($args['folderid'] != $resourceid)
        {
        throw new Exception('Upload token does not match itemid', MIDAS_INVALID_PARAMETER);
        }
      $folderModel = $modelLoader->loadModel('Folder');
      $folder = $folderModel->load($args['folderid']);
      if($folder == false)
        {
        throw new Exception('Unable to find folder', MIDAS_INVALID_PARAMETER);
        }
      if(!$folderModel->policyCheck($folder, $userDao, MIDAS_POLICY_WRITE))
        {
        throw new Exception('Permission error', MIDAS_INVALID_POLICY);
        }
      }
    else
      {
      throw new Exception('You must specify an itemid or folderid to upload into', MIDAS_INVALID_PARAMETER);
      }

    $mode = array_key_exists('mode', $args) ? $args['mode'] : 'stream';
    $uploadApi = new KwUploadAPI($this->apiSetup);

    if(array_key_exists('testingmode', $args))
      {
      $uploadApi->testing_enable = true;
      $args['localinput'] = $this->apiSetup['tmp_directory'].'/'.$args['filename'];
      }

    // Use KWUploadApi to handle the actual file upload
    if($mode == 'stream')
      {
      $result = $uploadApi->process($args);

      $filename = $result['filename'];
      $filepath = $result['path'];
      $filesize = $result['size'];
      $filemd5 = $result['md5'];
      }
    else if($mode == 'multipart')
      {
      if(!array_key_exists('file', $args) || !array_key_exists('file', $_FILES))
        {
        throw new Exception('Parameter file is not defined', MIDAS_INVALID_PARAMETER);
        }
      $file = $_FILES['file'];

      $filename = $file['name'];
      $filepath = $file['tmp_name'];
      $filesize = $file['size'];
      $filemd5 = '';
      }
    else
      {
      throw new Exception('Invalid upload mode', MIDAS_INVALID_PARAMETER);
      }

    $componentLoader = new MIDAS_ComponentLoader();
    $uploadComponent = $componentLoader->loadComponent('Upload');
    if(isset($folder))
      {
      $item = $uploadComponent->createUploadedItem($userDao, $filename, $filepath, $folder, '', $filemd5);
      }
    else if(isset($revision))
      {
      $tmp = array($item->getKey(), $revision->getRevision()); //existing revision
      $item = $uploadComponent->createNewRevision($userDao, $filename, $filepath, $tmp, '', null, $filemd5);
      }
    else
      {
      $tmp = array($item->getKey(), 99999); //new revision
      $item = $uploadComponent->createNewRevision($userDao, $filename, $filepath, $tmp, '', null, $filemd5);
      }

    if(!$item)
      {
      throw new Exception('Upload failed', MIDAS_INTERNAL_ERROR);
      }
    if($filesize == $args['length'])
      {
      unlink($filepath);
      }
    return $item->toArray();
    }

  /**
   * Create a new community or update an existing one using the uuid
   * @param token Authentication token
   * @param name The community name
   * @param description (Optional) The community description
   * @param uuid (Optional) Uuid of the community. If none is passed, will generate one.
   * @param privacy (Optional) Default 'Public'.
   * @param canjoin (Optional) Default 'Everyone'.
   * @return The community dao that was created
   */
  function communityCreate($args)
    {
    $this->_validateParams($args, array('name'));
    $userDao = $this->_getUser($args);
    if($userDao == false)
      {
      throw new Exception('Unable to find user', MIDAS_INVALID_POLICY);
      }

    $name = $args['name'];
    $uuid = isset($args['uuid']) ? $args['uuid'] : '';

    $componentLoader = new MIDAS_ComponentLoader();
    $modelLoader = new MIDAS_ModelLoader();
    $uuidComponent = $componentLoader->loadComponent('Uuid');
    $communityModel = $modelLoader->loadModel('Community');
    $record = false;
    if(!empty($uuid))
      {
      $record = $uuidComponent->getByUid($uuid);
      }
    if($record != false && $record instanceof CommunityDao)
      {
      if(!$communityModel->policyCheck($record, $userDao, MIDAS_POLICY_WRITE))
        {
        throw new Exception('Invalid policy', MIDAS_INVALID_POLICY);
        }
      $record->setName($name);
      if(isset($args['description']))
        {
        $record->setDescription($args['description']);
        }
      if(isset($args['privacy']))
        {
        $record->setPrivacy($args['privacy']);
        }
      if(isset($args['canjoin']))
        {
        $record->setCanJoin($args['canjoin']);
        }
      $communityModel->save($record);
      return $record->toArray();
      }
    else
      {
      // Policy check to make sure the user can create top level communities (admins only?)
      $description = '';
      $privacy = MIDAS_COMMUNITY_PUBLIC;
      $canJoin = MIDAS_COMMUNITY_CAN_JOIN;
      if(isset($args['description']))
        {
        $description = $args['description'];
        }
      if(isset($args['privacy']))
        {
        $privacy = $args['privacy'];
        }
      if(isset($args['canjoin']))
        {
        $canJoin = $args['canjoin'];
        }
      $communityDao = $communityModel->createCommunity($name, $description, $privacy, $userDao, $canJoin, $uuid);

      if($communityDao === false)
        {
        throw new Exception('Create community failed', MIDAS_INTERNAL_ERROR);
        }

      return $communityDao->toArray();
      }
    }

  /**
   * Get a community's information
   * @param token (Optional) Authentication token
   * @param id The id of the community
   * @return The community information
   */
  function communityGet($args)
    {
    if(!array_key_exists('id', $args))
      {
      throw new Exception('Parameter id is not defined', MIDAS_INVALID_PARAMETER);
      }
    $userDao = $this->_getUser($args);

    $modelLoader = new MIDAS_ModelLoader();
    $communityModel = $modelLoader->loadModel('Community');
    $community = $communityModel->load($args['id']);

    if($community === false || !$communityModel->policyCheck($community, $userDao, MIDAS_POLICY_READ))
      {
      throw new Exception("This community doesn't exist or you don't have the permissions.", MIDAS_INVALID_POLICY);
      }

    return $community->toArray();
    }

  /**
   * Get the immediate children of a community (non-recursive)
   * @param token (Optional) Authentication token
   * @param id The id of the community
   * @return The folders in the community
   */
  function communityChildren($args)
    {
    $this->_validateParams($args, array('id'));

    $id = $args['id'];

    $modelLoader = new MIDAS_ModelLoader();
    $communityModel = $modelLoader->loadModel('Community');
    $folderModel = $modelLoader->loadModel('Folder');
    $community = $communityModel->load($id);
    if(!$community)
      {
      throw new Exception('Invalid community id', MIDAS_INVALID_PARAMETER);
      }
    $folder = $folderModel->load($community->getFolderId());

    $userDao = $this->_getUser($args);
    try
      {
      $folders = $folderModel->getChildrenFoldersFiltered($folder, $userDao);
      }
    catch(Exception $e)
      {
      throw new Exception($e->getMessage(), MIDAS_INTERNAL_ERROR);
      }

    return array('folders' => $folders);
    }

  /**
   * Return a list of all communities visible to a user
   * @param token (Optional) Authentication token
   * @return A list of all communities
   */
  function communityList($args)
    {
    $userDao = $this->_getUser($args);
    $modelLoader = new MIDAS_ModelLoader();
    $communityModel = $modelLoader->loadModel('Community');
    $userModel = $modelLoader->loadModel('User');

    if($userDao && $userDao->isAdmin())
      {
      $communities = $communityModel->getAll();
      }
    else
      {
      $communities = $communityModel->getPublicCommunities();
      if($userDao)
        {
        $communities = array_merge($communities, $userModel->getUserCommunities($userDao));
        }
      }

    $componentLoader = new MIDAS_ComponentLoader();
    $sortDaoComponent = $componentLoader->loadComponent('Sortdao');
    $sortDaoComponent->field = 'name';
    $sortDaoComponent->order = 'asc';
    usort($communities, array($sortDaoComponent, 'sortByName'));
    return $sortDaoComponent->arrayUniqueDao($communities);
    }

  /**
   * Delete a community. Requires admin privileges on the community
   * @param token Authentication token
   * @param id The id of the community
   */
  function communityDelete($args)
    {
    $this->_validateParams($args, array('id'));

    $userDao = $this->_getUser($args);
    if($userDao == false)
      {
      throw new Exception('Unable to find user', MIDAS_INVALID_TOKEN);
      }
    $id = $args['id'];

    $modelLoader = new MIDAS_ModelLoader();
    $communityModel = $modelLoader->loadModel('Community');
    $community = $communityModel->load($id);

    if($community === false || !$communityModel->policyCheck($community, $userDao, MIDAS_POLICY_ADMIN))
      {
      throw new Exception("This community doesn't exist  or you don't have the permissions.", MIDAS_INVALID_POLICY);
      }

    $communityModel->delete($community);
    }

  /**
   * Create a folder or update an existing one if one exists by the uuid passed
   * @param token Authentication token
   * @param name The name of the folder to create
   * @param description (Optional) The description of the folder
   * @param uuid (Optional) Uuid of the folder. If none is passed, will generate one.
   * @param privacy (Optional) Default 'Public'.
   * @param parentid The id of the parent folder
   * @return The folder object that was created
   */
  function folderCreate($args)
    {
    $this->_validateParams($args, array('name'));
    $userDao = $this->_getUser($args);
    if($userDao == false)
      {
      throw new Exception('Cannot create folder anonymously', MIDAS_INVALID_POLICY);
      }

    $modelLoader = new MIDAS_ModelLoader();
    $folderModel = $modelLoader->loadModel('Folder');
    $name = $args['name'];
    $description = $args['description'];

    $uuid = isset($args['uuid']) ? $args['uuid'] : '';
    $record = false;
    if(!empty($uuid))
      {
      $componentLoader = new MIDAS_ComponentLoader();
      $uuidComponent = $componentLoader->loadComponent('Uuid');
      $record = $uuidComponent->getByUid($uuid);
      }
    if($record != false && $record instanceof FolderDao)
      {
      if(!$folderModel->policyCheck($record, $userDao, MIDAS_POLICY_WRITE))
        {
        throw new Exception('Invalid policy', MIDAS_INVALID_POLICY);
        }
      $record->setName($name);
      if(isset($args['description']))
        {
        $record->setDescription($args['description']);
        }
      if(isset($args['privacy']))
        {
        $record->setPrivacy($args['privacy']);
        }
      $folderModel->save($record);
      return $record->toArray();
      }
    else
      {
      if(!array_key_exists('parentid', $args))
        {
        throw new Exception('Parameter parentid is not defined', MIDAS_INVALID_PARAMETER);
        }
      if($args['parentid'] == -1) //top level user folder being created
        {
        $new_folder = $folderModel->createFolder($name, $description, $userDao->getFolderId(), $uuid);
        }
      else //child of existing folder
        {
        $folder = $folderModel->load($args['parentid']);
        if($folder == false)
          {
          throw new Exception('Parent doesn\'t exist', MIDAS_INVALID_PARAMETER);
          }
        $new_folder = $folderModel->createFolder($name, $description, $folder, $uuid);
        if($new_folder === false)
          {
          throw new Exception('Create folder failed', MIDAS_INTERNAL_ERROR);
          }
        $policyGroup = $folder->getFolderpolicygroup();
        $policyUser = $folder->getFolderpolicyuser();
        $folderpolicygroupModel = $modelLoader->loadModel('Folderpolicygroup');
        $folderpolicyuserModel = $modelLoader->loadModel('Folderpolicygroup');
        foreach($policyGroup as $policy)
          {
          $folderpolicygroupModel->createPolicy($policy->getGroup(), $new_folder, $policy->getPolicy());
          }
        foreach($policyUser as $policy)
          {
          $folderpolicyuserModel->createPolicy($policy->getUser(), $new_folder, $policy->getPolicy());
          }
        }

      return $new_folder->toArray();
      }
    }

  /**
   * Get information about the folder
   * @param token (Optional) Authentication token
   * @param id The id of the folder
   * @return The folder object, including its parent object
   */
  function folderGet($args)
    {
    $this->_validateParams($args, array('id'));
    $userDao = $this->_getUser($args);

    $modelLoader = new MIDAS_ModelLoader();
    $folderModel = $modelLoader->loadModel('Folder');

    $id = $args['id'];
    $folder = $folderModel->load($id);

    if($folder === false || !$folderModel->policyCheck($folder, $userDao, MIDAS_POLICY_READ))
      {
      throw new Exception("This folder doesn't exist or you don't have the permissions.", MIDAS_INVALID_POLICY);
      }

    $arr = $folder->toArray();
    $arr['parent'] = $folder->getParent();
    return $arr;
    }

  /**
   * Get the immediate children of a folder (non-recursive)
   * @param token (Optional) Authentication token
   * @param id The id of the folder
   * @return The items and folders in the given folder
   */
  function folderChildren($args)
    {
    $this->_validateParams($args, array('id'));

    $id = $args['id'];
    $modelLoader = new MIDAS_ModelLoader();
    $folderModel = $modelLoader->loadModel('Folder');
    $folder = $folderModel->load($id);

    $userDao = $this->_getUser($args);
    try
      {
      $folders = $folderModel->getChildrenFoldersFiltered($folder, $userDao);
      $items = $folderModel->getItemsFiltered($folder, $userDao);
      }
    catch(Exception $e)
      {
      throw new Exception($e->getMessage(), MIDAS_INTERNAL_ERROR);
      }

    return array('folders' => $folders, 'items' => $items);
    }

  /**
   * Delete a folder. Requires admin privileges on the folder
   * @param token Authentication token
   * @param id The id of the folder
   */
  function folderDelete($args)
    {
    $this->_validateParams($args, array('id'));

    $userDao = $this->_getUser($args);
    if($userDao == false)
      {
      throw new Exception('Unable to find user', MIDAS_INVALID_TOKEN);
      }
    $id = $args['id'];
    $modelLoader = new MIDAS_ModelLoader();
    $folderModel = $modelLoader->loadModel('Folder');
    $folder = $folderModel->load($id);

    if($folder === false || !$folderModel->policyCheck($folder, $userDao, MIDAS_POLICY_ADMIN))
      {
      throw new Exception("This folder doesn't exist  or you don't have the permissions.", MIDAS_INVALID_POLICY);
      }

    $folderModel->delete($folder);
    }

  /**
   * Download a folder
   * @param token (Optional) Authentication token
   * @param id The id of the folder
   * @return A zip archive of the folder's contents
   */
  function folderDownload($args)
    {
    $this->_validateParams($args, array('id'));
    $userDao = $this->_getUser($args);

    $id = $args['id'];
    $modelLoader = new MIDAS_ModelLoader();
    $folderModel = $modelLoader->loadModel('Folder');
    $folder = $folderModel->load($id);

    if($folder === false || !$folderModel->policyCheck($folder, $userDao, MIDAS_POLICY_READ))
      {
      throw new Exception("This folder doesn't exist or you don't have the permissions.", MIDAS_INVALID_POLICY);
      }

    $this->controller->redirect('/download/?folders='.$folder->getKey());
    }

  /**
   * Create an item or update an existing one if one exists by the uuid passed
   * @param token Authentication token
   * @param name The name of the item to create
   * @param description (Optional) The description of the item
   * @param uuid (Optional) Uuid of the item. If none is passed, will generate one.
   * @param privacy (Optional) Default 'Public'.
   * @param parentid The id of the parent folder
   * @return The item object that was created
   */
  function itemCreate($args)
    {
    $this->_validateParams($args, array('name'));
    $userDao = $this->_getUser($args);
    if($userDao == false)
      {
      throw new Exception('Cannot create item anonymously', MIDAS_INVALID_POLICY);
      }

    $modelLoader = new MIDAS_ModelLoader();
    $itemModel = $modelLoader->loadModel('Item');
    $name = $args['name'];
    $description = $args['description'];

    $uuid = isset($args['uuid']) ? $args['uuid'] : '';
    $record = false;
    if(!empty($uuid))
      {
      $componentLoader = new MIDAS_ComponentLoader();
      $uuidComponent = $componentLoader->loadComponent('Uuid');
      $record = $uuidComponent->getByUid($uuid);
      }
    if($record != false && $record instanceof ItemDao)
      {
      if(!$itemModel->policyCheck($record, $userDao, MIDAS_POLICY_WRITE))
        {
        throw new Exception('Invalid policy', MIDAS_INVALID_POLICY);
        }
      $record->setName($name);
      if(isset($args['description']))
        {
        $record->setDescription($args['description']);
        }
      if(isset($args['privacy']))
        {
        $record->setPrivacy($args['privacy']);
        }
      $itemModel->save($record);
      return $record->toArray();
      }
    else
      {
      if(!array_key_exists('parentid', $args))
        {
        throw new Exception('Parameter parentid is not defined', MIDAS_INVALID_PARAMETER);
        }
      $folderModel = $modelLoader->loadModel('Folder');
      $folder = $folderModel->load($args['parentid']);
      if($folder == false)
        {
        throw new Exception('Parent folder doesn\'t exist', MIDAS_INVALID_PARAMETER);
        }
      $item = $itemModel->createItem($name, $description, $folder, $uuid);
      if($item === false)
        {
        throw new Exception('Create new item failed', MIDAS_INTERNAL_ERROR);
        }

      return $item->toArray();
      }
    }

  /**
   * Get an item's information
   * @param token (Optional) Authentication token
   * @param id The item id
   * @param head (Optional) only list the most recent revision
   * @return The item object
   */
  function itemGet($args)
    {
    $this->_validateParams($args, array('id'));
    $userDao = $this->_getUser($args);

    $itemid = $args['id'];
    $modelLoader = new MIDAS_ModelLoader();
    $itemModel = $modelLoader->loadModel('Item');
    $item = $itemModel->load($itemid);

    if($item === false || !$itemModel->policyCheck($item, $userDao, MIDAS_POLICY_READ))
      {
      throw new Exception("This item doesn't exist or you don't have the permissions.", MIDAS_INVALID_POLICY);
      }

    $itemArray = $item->toArray();

    $owningFolders = $item->getFolders();
    if(count($owningFolders) > 0)
      {
      $itemArray['folder_id'] = $owningFolders[0]->getKey();
      }

    $revisionsArray = array();
    if(array_key_exists('head', $args))
      {
      $revisions = array($itemModel->getLastRevision($item));
      }
    else //get all revisions
      {
      $revisions = $item->getRevisions();
      }

    foreach($revisions as $revision)
      {
      if(!$revision)
        {
        continue;
        }
      $bitstreamArray = array();
      $bitstreams = $revision->getBitstreams();
      foreach($bitstreams as $b)
        {
        $bitstreamArray[] = $b->toArray();
        }
      $tmp = $revision->toArray();
      $tmp['bitstreams'] = $bitstreamArray;
      $revisionsArray[] = $tmp;
      }
    $itemArray['revisions'] = $revisionsArray;
    return $itemArray;
    }

  /**
   * Download an item
   * @param token (Optional) Authentication token
   * @param id The id of the item
   * @param revision (Optional) Revision to download. Defaults to latest revision
   * @return The bitstream(s) in the item
   */
  function itemDownload($args)
    {
    $this->_validateParams($args, array('id'));
    $userDao = $this->_getUser($args);

    $id = $args['id'];
    $modelLoader = new MIDAS_ModelLoader();
    $itemModel = $modelLoader->loadModel('Item');
    $item = $itemModel->load($id);

    if($item === false || !$itemModel->policyCheck($item, $userDao, MIDAS_POLICY_READ))
      {
      throw new Exception("This item doesn't exist or you don't have the permissions.", MIDAS_INVALID_POLICY);
      }

    if(isset($args['revision']))
      {
      $this->controller->redirect('/download/?items='.$item->getKey().','.$args['revision']);
      }
    else
      {
      $this->controller->redirect('/download/?items='.$item->getKey());
      }
    }

  /**
   * Delete an item
   * @param token Authentication token
   * @param id The id of the item
   */
  function itemDelete($args)
    {
    $this->_validateParams($args, array('id'));

    $userDao = $this->_getUser($args);
    if($userDao == false)
      {
      throw new Exception('Unable to find user', MIDAS_INVALID_TOKEN);
      }
    $id = $args['id'];
    $modelLoader = new MIDAS_ModelLoader();
    $itemModel = $modelLoader->loadModel('Item');
    $item = $itemModel->load($id);

    if($item === false || !$itemModel->policyCheck($item, $userDao, MIDAS_POLICY_ADMIN))
      {
      throw new Exception("This item doesn't exist or you don't have the permissions.", MIDAS_INVALID_POLICY);
      }

    $itemModel->delete($item);
    }

  /**
   * Get the item's metadata
   * @param token (Optional) Authentication token
   * @param id The id of the item
   * @param revision (Optional) Revision of the item. Defaults to latest revision
   */
  function itemGetmetadata($args)
    {
    $this->_validateParams($args, array('id'));
    $userDao = $this->_getUser($args);

    $itemid = $args['id'];
    $modelLoader = new MIDAS_ModelLoader();
    $itemModel = $modelLoader->loadModel('Item');
    $item = $itemModel->load($itemid);

    if($item === false || !$itemModel->policyCheck($item, $userDao, MIDAS_POLICY_READ))
      {
      throw new Exception("This item doesn't exist or you don't have the permissions.", MIDAS_INVALID_POLICY);
      }

    if(isset($args['revision']))
      {
      $revisionNumber = $args['revision'];
      $revisions = $item->getRevisions();
      foreach($revisions as $revision)
        {
        if($revisionNumber == $revision->getRevision())
          {
          $revisionDao = $revision;
          break;
          }
        }
      }

    if(!isset($revisionDao))
      {
      $revisionDao = $itemModel->getLastRevision($item);
      }

    $itemRevisionModel = $modelLoader->loadModel('ItemRevision');
    $metadata = $itemRevisionModel->getMetadata($revisionDao);
    $metadataArray = array();
    foreach($metadata as $m)
      {
      $metadataArray[] = $m->toArray();
      }
    return $metadataArray;
    }

  /**
   * Return a list of top level folders belonging to the user
   * @param token Authentication token
   * @return List of the user's top level folders
   */
  function userFolders($args)
    {
    $userDao = $this->_getUser($args);
    if($userDao == false)
      {
      return array();
      }

    $userRootFolder = $userDao->getFolder();
    $modelLoader = new MIDAS_ModelLoader();
    $folderModel = $modelLoader->loadModel('Folder');
    return $folderModel->getChildrenFoldersFiltered($userRootFolder, $userDao, MIDAS_POLICY_READ);
    }

  /**
   * Returns the user's default API key given their username and password.
       POST method required.
   * @param email The user's email
   * @param password The user's password
   * @return The user's default API key
   */
  function userApikeyDefault($args)
    {
    $this->_validateParams($args, array('email', 'password'));
    if(!$this->controller->getRequest()->isPost())
      {
      throw new Exception('POST method required', MIDAS_HTTP_ERROR);
      }

    $salt = Zend_Registry::get('configGlobal')->password->prefix;
    $defaultApiKey = $key = md5($args['email'].md5($salt.$args['password']).'Default');
    return array('apikey' => $defaultApiKey);
    }

  /**
   * Fetch the information about a bitstream
   * @param token (Optional) Authentication token
   * @param id The id of the bitstream
   * @return Bitstream dao
   */
  function bitstreamGet($args)
    {
    $this->_validateParams($args, array('id'));
    $userDao = $this->_getUser($args);
    $modelLoader = new MIDAS_ModelLoader();
    $bitstreamModel = $modelLoader->loadModel('Bitstream');
    $bitstream = $bitstreamModel->load($args['id']);

    if(!$bitstream)
      {
      throw new Exception('Invalid bitstream id', MIDAS_INVALID_PARAMETER);
      }

    if(array_key_exists('name', $args))
      {
      $bitstream->setName($args['name']);
      }
    $revisionModel = $modelLoader->loadModel('ItemRevision');
    $revision = $revisionModel->load($bitstream->getItemrevisionId());

    if(!$revision)
      {
      throw new Exception('Invalid revision id', MIDAS_INTERNAL_ERROR);
      }
    $itemModel = $modelLoader->loadModel('Item');
    $item = $itemModel->load($revision->getItemId());
    if(!$item || !$itemModel->policyCheck($item, $userDao, MIDAS_POLICY_READ))
      {
      throw new Exception("This item doesn't exist or you don't have the permissions.", MIDAS_INVALID_POLICY);
      }
    $bitstreamArray = array();
    $bitstreamArray['name'] = $bitstream->getName();
    $bitstreamArray['size'] = $bitstream->getSizebytes();
    $bitstreamArray['mimetype'] = $bitstream->getMimetype();
    $bitstreamArray['checksum'] = $bitstream->getChecksum();
    $bitstreamArray['itemrevision_id'] = $bitstream->getItemrevisionId();
    $bitstreamArray['item_id'] = $revision->getItemId();
    return $bitstreamArray;
    }

  /**
   * Download a bitstream either by its id or by a checksum.
       Either an id or checksum parameter is required.
   * @param token (Optional) Authentication token
   * @param id (Optional) The id of the bitstream
   * @param checksum (Optional) The checksum of the bitstream
   * @param name (Optional) Alternate filename to download as
   * @param offset (Optional) The download offset in bytes (used for resume)
   */
  function bitstreamDownload($args)
    {
    if(!array_key_exists('id', $args) && !array_key_exists('checksum', $args))
      {
      throw new Exception('Either an id or checksum parameter is required', MIDAS_INVALID_PARAMETER);
      }
    $userDao = $this->_getUser($args);
    $modelLoader = new MIDAS_ModelLoader();
    $bitstreamModel = $modelLoader->loadModel('Bitstream');
    if(array_key_exists('id', $args))
      {
      $bitstream = $bitstreamModel->load($args['id']);
      }
    else
      {
      $bitstream = $bitstreamModel->getByChecksum($args['checksum']);
      }

    if(!$bitstream)
      {
      throw new Exception('Invalid bitstream id', MIDAS_INVALID_PARAMETER);
      }

    if(array_key_exists('name', $args))
      {
      $bitstream->setName($args['name']);
      }
    $revisionModel = $modelLoader->loadModel('ItemRevision');
    $revision = $revisionModel->load($bitstream->getItemrevisionId());

    if(!$revision)
      {
      throw new Exception('Invalid revision id', MIDAS_INTERNAL_ERROR);
      }
    $itemModel = $modelLoader->loadModel('Item');
    $item = $itemModel->load($revision->getItemId());
    if(!$item || !$itemModel->policyCheck($item, $userDao, MIDAS_POLICY_READ))
      {
      throw new Exception("This item doesn't exist or you don't have the permissions.", MIDAS_INVALID_POLICY);
      }
    if(strpos($bitstream->getPath(), 'http://') !== false)
      {
      $this->_redirect($bitstream->getPath());
      return;
      }
    $offset = array_key_exists('offset', $args) ? $args['offset'] : 0;

    $componentLoader = new MIDAS_ComponentLoader();
    $downloadComponent = $componentLoader->loadComponent('DownloadBitstream');
    $downloadComponent->download($bitstream, $offset);
    }
  } // end class
