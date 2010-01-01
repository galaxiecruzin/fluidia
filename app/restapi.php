<?php
/*

Basic REST functions: Copyright (C) 2004 Paul James <paul@peej.co.uk>
Additional coding and customizations (C) 2009 Alex Barger <cruzinthegalaxie@gmail.com>

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/


/**
 * PHP REST class
 */
class restapi {
    
    /**
     * Parsed configuration file
     * @var str[]
     */
    var $config;
    
    /**
     * Database connection
     * @var resource
     */
    var $db;
    
    /**
     * The HTTP request method used.
     * @var str
     */
    var $method = 'GET';
	
    /**
     * The HTTP request data sent (if any).
     * @var str
     */
    var $requestData = NULL;
	
    /**
     * The URL extension stripped off of the request URL
     * @var str
     */
    var $extension = NULL;
	
    /**
     * The database table to query.
     * @var str
     */
    var $table = NULL;

    /**
     * The user credentials
     * @var str[]
     */
    var $user = array('userid' => false, 'role' => 0, 'active' => 0);

    /**
     * The internal function
     * @var str
     */
    var $controller_function = NULL;

    /**
     * The internal functions
     * @var str[]
     */
    var $internal_methods = array('users','users_has_projects','projects','revisions','sessions');

    /**
     * The publically available functions
     * @var str[]
     */
    var $public_methods = array('login','logged_in','logout','email_exists','register','check_session','load_session','save_session','download_session','upload_fld');

    /**
     * The primary key of the database row to query.
     * @var str[]
     */
    var $uid = NULL;
    
    /**
     * Array of strings to convert into the HTTP response.
     * @var str[]
     */
    var $output = array();
    
    /**
     * Type of display, database, table or row.
     * @var str
     */
    var $display = NULL;
    
    /**
     * Constructor. Parses the configuration file "restapi.ini", grabs any request data sent, records the HTTP
     * request method used and parses the request URL to find out the requested table name and primary key values.
     * @param str iniFile Configuration file to use
     */
    function restapi($iniFile = 'restapi.ini') {
	// Load user session
	if(is_array(@$_SESSION['user'])) $this->user = $_SESSION['user'];
	//
        $this->config = parse_ini_file($iniFile, TRUE);
        
        if (isset($_SERVER['REQUEST_URI']) && isset($_SERVER['REQUEST_METHOD'])) {
        
            if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
                $this->requestData = '';
                $httpContent = fopen('php://input', 'r');
                while ($data = fread($httpContent, 1024)) {
                    $this->requestData .= $data;
                }
                fclose($httpContent);
            }
            
            $urlString = substr($_SERVER['REQUEST_URI'], strlen($this->config['settings']['baseURL']));
			$urlParts = explode('/', $urlString);
			
			$lastPart = array_pop($urlParts);
			$dotPosition = strpos($lastPart, '.');
			$dotPosition = strpos($lastPart, '.');
			if ($dotPosition !== FALSE) {
				$this->extension = substr($lastPart, $dotPosition + 1);
				$lastPart = substr($lastPart, 0, $dotPosition);
			}
			array_push($urlParts, $lastPart);
			
			if (isset($urlParts[0]) && $urlParts[0] == '') {
				array_shift($urlParts);
			}
			
            if (isset($urlParts[0])) $this->table = $urlParts[0];
            if (count($urlParts) > 1 && $urlParts[1] != '') {
                array_shift($urlParts);
                foreach ($urlParts as $uid) {
                    if ($uid != '') {
                        $this->uid[] = $uid;
                    }
                }
            }
            
            $this->method = $_SERVER['REQUEST_METHOD'];
            
        }
    }
    
    /**
     * Connect to the database.
     */
    function connect() {
        $database = $this->config['database']['type'];
        require_once($database.'.php');
        $this->db = new $database(); 
        if (isset($this->config['database']['username']) && isset($this->config['database']['password'])) {
            if (!$this->db->connect($this->config['database'])) {
                trigger_error('Could not connect to server', E_USER_ERROR);
            }
        } elseif (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
			$this->config['database']['username'] = $_SERVER['PHP_AUTH_USER'];
			$this->config['database']['password'] = $_SERVER['PHP_AUTH_PW'];
            if (!$this->db->connect($this->config['database'])) {
                $this->unauthorized();
                exit;
            }
        } else {
            $this->unauthorized();
            exit;
        }
    }
    
    /**
     * Execute the request.
     */
    function exec() {

        //var_dump($this->table);

	$this->output['log'] = "#----Exec Start----.\n";
        if($this->table == ''){
	    $this->output['log'] .= "No Parameters.\n";
            $this->controller_function = 'home_index';
	    $this->go();
            exit;
        } elseif(in_array($this->table,$this->public_methods)){
	    $this->output['log'] .= "Public Method Called:".$this->table.".\n";
            $this->controller_function = $this->table;
            $this->connect();
	    $this->go();
        } elseif(in_array($this->table,$this->internal_methods)){
	    // Time to check basic permissions
	    // Currently everything that gets here requires login
	    $this->output['log'] .= "Authentication Required.\n";
            $this->connect();
	    $this->require_login();
	};
	//
        
        //$this->connect();
        
        switch ($this->method) {
            case 'GET':
                $this->get();
                break;
            case 'POST':
                $this->post();
                break;
            case 'PUT':
                $this->put();
                break;
            case 'DELETE':
                $this->delete();
                break;
        }
   
        $this->db->close();

        //var_dump($_SESSION); 
    }

    // Run dynamic controller method
    function go(){
       if(method_exists($this,$this->controller_function)){
          $function = $this->controller_function;
          $this->$function();
       }
    }

    function is_logged_in(){
        if($this->user['userid'] != false && $this->user['role'] > 0 && $this->user['active'] == 1){
	   //$this->output['struct']['error'] = '';
	   return true;
	} else {
	   //$this->output['struct']['error'] = 'User not active, or role = 0';
	   if($this->output['struct']['statuscode'] != 401) $this->output['struct']['statuscode'] = 403;
	   return false;
	}
    }

    function logged_in(){
	$this->output['struct']['result_type'] = 'bool';
	$this->output['struct']['result'] = '';
	$this->output['struct']['error'] = '';
        if($this->is_logged_in()){
	   $this->output['struct']['result'] = 'true';
	} else {
	   $this->output['struct']['result'] = 'false';
	}
	$this->output['struct']['SESSION'] = $_SESSION;
	$this->display = 'struct';
	$this->simpleResponse();
    }

    function require_login(){
	if($this->is_logged_in()){
	   $this->output['log'] .= "You are logged in as user:".$_SESSION['user']['username'];
        } else {
	   // Not logged in
	   $this->unauthorized();
	   $this->login();
	   if($this->is_logged_in()){
	      $this->redirect($this->table);
	   } else {
	      echo "You are not logged in.";
	      exit;
	   }
        }
    }

    function login(){
	$this->output['struct']['result_type'] = 'struct';
	$this->output['struct']['result'] = '';
	$this->output['struct']['error'] = '';
	if(!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])){
	   $this->output['struct']['result'] = 'false';
	   $this->output['struct']['error'] = 'Must send authentication headers';
	}
	if(@$_SERVER['PHP_AUTH_USER'] == NULL || @$_SERVER['PHP_AUTH_PW'] == NULL){
	   $this->output['struct']['result'] = 'false';
	   $this->output['struct']['error'] = 'Must send username and password';
	}

	// if user is all good then load the user session
	if($this->output['struct']['error'] === ''){
	   $where = " `username` = '".$_SERVER['PHP_AUTH_USER']."'";
	   $where .= " AND `password` = '".$_SERVER['PHP_AUTH_PW']."'";
	   $where .= ' LIMIT 1 ';
	   $resource = $this->db->getRow('users', $where);

	   if(is_resource($resource)){
	      $this->output['struct']['error'] = 'Record Found';
              if ($this->db->numRows($resource) == 1) {
                 $row = $this->db->row($resource);
                 $this->user = array('userid' => $row['username'], 'role' => $row['role'], 'active' => $row['active']);
	         $this->output['struct']['error'] = '';
	      $this->output['struct']['statuscode'] = 200;
	      } else {
                 $this->user = array('userid' => NULL, 'role' => 0, 'active' => 0);
	         $this->output['struct']['error'] = 'Bad username password combination.';
	         $this->output['struct']['statuscode'] = 401;
	      }
	      $_SESSION['user'] = $this->user;
	   } else {
              $this->user = array('userid' => NULL, 'role' => 0, 'active' => 0);
	      $_SESSION['user'] = $this->user;
	      $this->output['struct']['error'] = 'Error performing database query.';
	      $this->output['struct']['statuscode'] = 500;
	   }
	}

	//output
	$this->output['struct']['SESSION'] = $_SESSION;
	$this->output['struct']['result'] = ($this->is_logged_in()) ? 'logged_in' : 'not_logged_in';
	$this->display = 'struct';
	$this->simpleResponse();
    }

    function logout(){
    	$this->user = array('userid' => false, 'role' => 0, 'active' => 0);
	$_SESSION['user'] = $this->user;
	$this->unauthorized();

	$this->output['struct']['result_type'] = 'bool';
	$this->output['struct']['result'] = 'true';
	$this->output['struct']['error'] = '';
	$this->display = 'struct';
	$this->simpleResponse();
    }

    function home_index(){
	include('test.php');
	return false;
    }

    function email_exists(){
	// Check to see if email exists in db
	$this->output['struct']['result'] = 'false';
	$this->output['struct']['result_type'] = 'bool';
	$this->output['struct']['error'] = '';
	if(!isset($_GET['email']) || strlen($_GET['email'])<3){
	   $this->output['struct']['error'] = 'Must provide get[email] of at least 3 characters';
	   $this->output['struct']['result'] = 'true';
	} else {
	   $where = " `username` LIKE '".$_GET['email']."%'";
	   $where .= ' LIMIT 1 ';
	   $resource = $this->db->getRow('users', $where);
	   $this->output['struct']['result'] = ($this->db->numRows($resource) > 0) ? 'true' : 'false';
	}
	//output
	$this->display = 'struct';
	$this->simpleResponse();
    }

    function register(){
	ob_start();
	// Check to see if email exists in db
	$this->output['struct']['result'] = 'false';
	$this->output['struct']['result_type'] = 'bool';
	$this->output['struct']['error'] = '';
	$errors = false;

	if(!isset($_POST['email']) || strlen($_POST['email'])<6){
	   $this->output['struct']['error'] = 'Must provide pst[email] of at least 6 characters';
	   $this->output['struct']['result'] = 'false';
	   $errors = TRUE;
	}
	if(!isset($_POST['passwd']) || strlen($_POST['passwd'])<6){
	   $this->output['struct']['error'] .= ' Must provide post[passwd] of at least 6 characters';
	   $this->output['struct']['result'] = 'false';
	   $errors = TRUE;
	}

	if(!$errors){
           $names = 'username`, `password';
           $values = $_POST['email'].'","'.$_POST['passwd'];

	   // Check if email exists
	   $where = " `username` LIKE '".$_POST['email']."%'";
	   $where .= ' LIMIT 1 ';
           $resource = $this->db->getRow('users', $where);
	   if ($resource && $this->db->numRows($resource) == 0) {
	      $resource = $this->db->insertRow('users', $names, $values);
	      $this->output['struct']['result'] = ($this->db->numAffected($resource) > 0) ? 'true' : 'false';
              $this->sendEmailConfirmation();
              $this->accepted();
	   } else {
	      $this->output['struct']['error'] .= ' Email already exists';
	      $this->output['struct']['result'] = 'false';
	   }
	} else {
           $this->notAcceptable();
	}
	//output
	$this->display = 'struct';
	$this->simpleResponse();
	exit; // prevent 500 Internal Server Issue
    }


    function sendEmailConfirmation(){
        // multiple recipients
        $to .= 'cruzinthegalaxie@gmail.com';

        // subject
        $subject = 'FluidIA - Confirm Your Email (' . rand(0,1000) . ')';

        // message
        $message = '
           <html>
           <head>
             <title>Confirm Your Email</title>
           </head>
           <body>
             <p>Testing... Click on the following link: <a href="http://google.com">click here.</a></p>
             <p>Or copy and paste this url into your browser:  http://google.com
             </p>
           </body>
          </html>
        ';

        // To send HTML mail, the Content-type header must be set
        $headers  = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";

        // Additional headers
        $headers .= 'To: Alex Barger <cruzinthegalaxie@gmail.com>' . "\r\n";
        $headers .= 'From: FluidIA <noreply@fluidia.org>' . "\r\n";

        // Mail it
        mail($to, $subject, $message, $headers);
    }

   function upload_fld(){
      $_SESSION['fluidia_object'][] = file_get_contents($_FILES['userfile']['tmp_name']);
      $this->accepted();
      exit();
   }

   function check_session(){
   	$this->output['struct']['result'] = 'true';
			$this->output['struct']['result_type'] = 'struct';
			$this->output['struct']['error'] = '';
			
			// Set Data Array
			$this->output['struct']['session_count'] = count($_SESSION['fluidia_object']);
			
			$this->display = 'struct';
			$this->simpleResponse();
   }

   function save_session(){
      if($_SERVER['REQUEST_METHOD'] == 'POST'){
         if(isset($_POST['fluidia_object'])){
            if(isset($_SESSION['fluidia_object'])){
               $_SESSION['fluidia_object'][] = $_POST['fluidia_object'];//json_decode($_POST['fluidia_object'], true);
            } else {
               $_SESSION['fluidia_object'][0] = $_POST['fluidia_object'];//json_decode($_POST['fluidia_object'], true);
            }
         } //else die("fluidia_object not defined");
      } //else die("Must be done over a POST request, sorry");
      
      $this->accepted();
      exit();
   }

	function load_session(){
		if(isset($_GET['key']) && is_numeric($_GET['key'])) $key = $_GET['key'];
		if($key >= 0){
      echo $_SESSION['fluidia_object'][$key];
      exit();
		}
	}

	function download_session($key=false){
		if(!$key) $key = count($_SESSION['fluidia_object']) - 1;
		if($key >= 0){
	      header("Pragma: public"); // required
	      header("Expires: 0");
	      header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
	      header("Cache-Control: private", false); // required for certain browsers
	      header("Content-Type: application/fluidia");
	      header("Content-Disposition: attachment; filename=\"fluidia-save.fld\";"); // FIXME-> Make Dynamic
	      header("Content-Transfer-Encoding: binary");
	      // Start Buffering
	      ob_start();
	      //
	      // Echo the contents -> Buffer
	      
	      echo $_SESSION['fluidia_object'][$key];
	      //
	      // Check the size of the buffer
	      $ob_level = ob_get_level();
	      $ob_status = ob_get_status(TRUE);
	      //
	      // Send Content-Length header with sizeof buffer
	      header("Content-Length: " . $ob_status[$ob_level]['size']);
	      //
	      // Send the data
	      ob_end_flush();
	      exit();
		} else {
			$this->output['struct']['result'] = 'false';
			$this->output['struct']['result_type'] = 'bool';
			$this->output['struct']['error'] = 'Nothing is saved in the session';
			$this->display = 'struct';
			$this->simpleResponse();
		}
	}

    function simpleResponse(){
	if($this->output){
	   // What are we rendering
	   if($pos = strpos($this->extension,'?')){
	      $this->extension = substr($this->extension,0,$pos);
	   }
	   if (isset($this->config['mimetypes'][$this->extension])) {
              $mimetype = $this->config['mimetypes'][$this->extension];
              if (isset($this->config['renderers'][$mimetype])) {
                 $renderClass = $this->config['renderers'][$mimetype];
              }
	   }
	   // We should have a renderer now
           if (isset($renderClass)) {
               require_once($renderClass);
               $renderer = new PHPRestSQLRenderer();
               $renderer->render($this);
           } else {
               $this->notAcceptable();
               exit;
           }
	}
    }

    /**
     * Get the primary keys for the request table.
     * @return str[] The primary key field names
     */
    function getPrimaryKeys() {
    	return $this->db->getPrimaryKeys($this->table);

        #$resource = $this->db->getColumns($this->table);
        #$primary = NULL;
        #if ($resource) {
        #    while ($row = $this->db->row($resource)) {
        #        if ($row['Key'] == 'PRI') {
        #            $primary[] = $row['Field'];
        #        }
        #    }
        #}
        #return $primary;
    }
    
    /**
     * Execute a GET request. A GET request fetches a list of tables when no table name is given, a list of rows
     * when a table name is given, or a table row when a table and primary key(s) are given. It does not change the
     * database contents.
     */
    function get() {
        if ($this->table) {
            $primary = $this->getPrimaryKeys();
            if ($primary) {
                if ($this->uid && count($primary) == count($this->uid)) { // get a row
                    $this->display = 'row';
                    $where = '';
                    foreach($primary as $key => $pri) {
                        $where .= $pri.' = \''.$this->uid[$key].'\' AND ';
                    }
                    $where = substr($where, 0, -5);
                    $resource = $this->db->getRow($this->table, $where);
                    if ($resource) {
                        if ($this->db->numRows($resource) > 0) {
                            while ($row = $this->db->row($resource)) {
                                $values = array();
                                foreach ($row as $column => $data) {
                                    $field = array(
                                        'field' => $column,
                                        'value' => $data
                                    );
                                    if (substr($column, -strlen($this->config['database']['foreignKeyPostfix'])) == $this->config['database']['foreignKeyPostfix']) {
										$field['xlink'] = $this->config['settings']['baseURL'].'/'.substr($column, 0, -strlen($this->config['database']['foreignKeyPostfix'])).'/'.$data;
                                    }
                                    $values[] = $field;
                                }
                                $this->output['row'] = $values;
                            }
                            $this->generateResponseData();
                        } else {
                            $this->notFound();
                        }
                    } else {
                        $this->unauthorized();
                    }
                } else { // get table
                    $this->display = 'table';
                    $resource = $this->db->getTable(join(', ', $primary), $this->table);
                    if ($resource) {
                        if ($this->db->numRows($resource) > 0) {
                            while ($row = $this->db->row($resource)) {
                                $this->output['table'][] = array(
                                    'xlink' => $this->config['settings']['baseURL'].'/'.$this->table.'/'.join('/', $row),
                                    'value' => join(' ', $row)
                                );
                            }
                        }
                        $this->generateResponseData();
                    } else {
                        $this->unauthorized();
                    }
                }
            }
        } else { // get database
            $this->display = 'database';
            $resource = $this->db->getDatabase();
            if ($resource) {
                if ($this->db->numRows($resource) > 0) {
                    while ($row = $this->db->row($resource)) {
                        $this->output['database'][] = array(
                            'xlink' => $this->config['settings']['baseURL'].'/'.reset($row),
                            'value' => reset($row)
                        );
                    }
                    $this->generateResponseData();
                } else {
                    $this->notFound();
                }
            } else {
                $this->unauthorized();
            }
        }
    }

    /**
     * Execute a POST request.
     */
    function post() {
        if ($this->table && $this->uid) {
            if ($this->requestData) {
                $primary = $this->getPrimaryKeys();
                if ($primary && count($primary) == count($this->uid)) { // update a row
                    $pairs = $this->parseRequestData();
                    $values = '';
                    foreach ($pairs as $column => $data) {
                        $values .= '`'.$column.'` = "'.$this->db->escape($data).'", ';
                    }
                    $values = substr($values, 0, -2);
                    $where = '';
                    foreach($primary as $key => $pri) {
                        $where .= $pri.' = \''.$this->uid[$key].'\' AND ';
                    }
                    $where = substr($where, 0, -5);
                    $resource = $this->db->updateRow($this->table, $values, $where);
                    if ($resource) {
                        if ($this->db->numAffected() > 0) {
                            $values = array();
                            foreach ($pairs as $column => $data) {
                                $field = array(
                                    'field' => $column,
                                    'value' => $data
                                );
                                if (substr($column, -strlen($this->config['database']['foreignKeyPostfix'])) == $this->config['database']['foreignKeyPostfix']) {
                                    $field['xlink'] = $this->config['settings']['baseURL'].'/'.substr($column, 0, -strlen($this->config['database']['foreignKeyPostfix'])).'/'.$data.'/';
                                }
                                $values[] = $field;
                            }
                            $this->output['row'] = $values;
                            $this->generateResponseData();
                        } else {
                            $this->badRequest();
                        }
                    } else {
                        $this->internalServerError();
                    }
                } else {
                    $this->badRequest();
                }
            } else {
                $this->lengthRequired();
            }
        } elseif ($this->table) { // insert a row without a uid
            if ($this->requestData) {
                $pairs = $this->parseRequestData();
                $values = join('", "', $pairs);
                $names = join('`, `', array_keys($pairs));
                $resource = $this->db->insertRow($this->table, $names, $values);
                if ($resource) {
                    if ($this->db->numAffected() > 0) {
						$this->created($this->config['settings']['baseURL'].'/'.$this->table.'/'.$this->db->lastInsertId().'/');
                    } else {
                        $this->badRequest();
                    }
                } else {
                    $this->internalServerError();
                }
            } else {
                $this->lengthRequired();
            }
        } else {
            $this->methodNotAllowed('GET, HEAD');
        }
    }

    /**
     * Execute a PUT request. A PUT request adds a new row to a table given a table and name=value pairs in the
     * request body.
     */
    function put() {
        if ($this->table && $this->uid) {
            if ($this->requestData) {
                $primary = $this->getPrimaryKeys();
                if ($primary && count($primary) == count($this->uid)) { // (attempt to) insert a row with a uid

                    // prepare data for INSERT
                    $pairs = $this->parseRequestData();
                    $values = join('", "', $this->uid).'", "'.join('", "', $pairs);
                    $names = join('`, `', $primary).'`, `'.join('`, `', array_keys($pairs));
                    
                    // prepare data for a SELECT (i.e. check wheter a
                    // row with the same ID/PKey exists)
                    # TODO: the same code is in many other places in this
                    # script, you should better write a function, then call it 
                    $where = '';
                    foreach($primary as $key => $pri) {
                        $where .= $pri.' = \''.$this->uid[$key].'\' AND ';
                    }
                    $where = substr($where, 0, -5);
                    #print("\nWHERE $where\n"); #DEBUG
                    #die(); #DEBUG

                    # imho calling insertRow is not robust because 
                    # relies on mysql failing silently on INSERT, then check 
                    # if number of affected rows == 0 to know wheter to 
                    # perform an UPDATE instead...  PostgreSQL is stricter
                    # and pg_query issues a Warning (which sounds reasonable).
                    # gd <guidoderosa@gmail.com>
                    #$resource = $this->db->insertRow($this->table, $names, $values);
                    # Do a SELECT (check) instead... 
                    $resource = $this->db->getRow($this->table, $where);
                    if ($resource && $this->db->numRows($resource) == 0) {
                        $resource = $this->db->insertRow($this->table, $names, $values);
                        $this->created();
                    } else {
                        $values = '';
                        foreach ($pairs as $column => $data) {
                            $values .= '`'.$column.'` = "'.$this->db->escape($data).'", ';
                        }
                        $values = substr($values, 0, -2);

                        # WHERE string ($where) already computed
                        #$where = '';
                        #foreach($primary as $key => $pri) {
                        #    $where .= $pri.' = '.$this->uid[$key].' AND ';
                        #}
                        #$where = substr($where, 0, -5);
                        $resource = $this->db->updateRow($this->table, $values, $where);
                        if ($resource) {
                            if ($this->db->numAffected() > 0) {
                                $this->noContent();
                            } else {
                                $this->badRequest();
                            }
                        } else {
                            $this->internalServerError();
                        }
                    }
                } else {
                    $this->badRequest();
                }
            } else {
                $this->lengthRequired();
            }
        } elseif ($this->table) {
            $this->methodNotAllowed('GET, HEAD, PUT');
        } else {
            $this->methodNotAllowed('GET, HEAD');
        }
    }
	
    /**
     * Execute a DELETE request. A DELETE request removes a row from the database given a table and primary key(s).
     */
    function delete() {
        if ($this->table && $this->uid) {
            $primary = $this->getPrimaryKeys();
            if ($primary && count($primary) == count($this->uid)) { // delete a row
                $where = '';
                foreach($primary as $key => $pri) {
                    $where .= $pri.' = \''.$this->uid[$key].'\' AND ';
                }
                $where = substr($where, 0, -5);
                $resource = $this->db->deleteRow($this->table, $where);
                if ($resource) {
                    if ($this->db->numAffected() > 0) {
                        $this->noContent();
                    } else {
                        $this->notFound();
                    }
                } else {
                    $this->unauthorized();
                }
            }
        } elseif ($this->table) {
            $this->methodNotAllowed('GET, HEAD, PUT');
        } else {
            $this->methodNotAllowed('GET, HEAD');
        }
    }
    
    /**
     * Parse the HTTP request data.
     * @return str[] Array of name value pairs
     */
    function parseRequestData() {
        $values = array();
        $pairs = explode("\n", $this->requestData);
        foreach ($pairs as $pair) {
            $parts = explode('=', $pair);
            if (isset($parts[0]) && isset($parts[1])) {
                $values[$parts[0]] = $this->db->escape($parts[1]);
            }
        }
        return $values;
    }
    
    /**
     * Generate the HTTP response data.
     */
    function generateResponseData() {
		if ($this->extension) {
			if (isset($this->config['mimetypes'][$this->extension])) {
				$mimetype = $this->config['mimetypes'][$this->extension];
				if (isset($this->config['renderers'][$mimetype])) {
					$renderClass = $this->config['renderers'][$mimetype];
				}
			}
		} elseif (isset($_SERVER['HTTP_ACCEPT'])) {
            $accepts = explode(',', $_SERVER['HTTP_ACCEPT']);
            $orderedAccepts = array();
            foreach ($accepts as $key => $accept) {
                $exploded = explode(';', $accept);
                if (isset($exploded[1]) && substr($exploded[1], 0, 2) == 'q=') {
                    $orderedAccepts[substr($exploded[1], 2)][] = $exploded[0];
                } else {
                    $orderedAccepts['1'][] = $exploded[0];
                }
            }
            krsort($orderedAccepts);
            foreach ($orderedAccepts as $acceptArray) {
                foreach ($acceptArray as $accept) {
                    if (isset($this->config['renderers'][$accept])) {
                        $renderClass = $this->config['renderers'][$accept];
                        break 2;
                    } else {
                        $grep = preg_grep('/'.str_replace(str_replace($accept, '*', '.*'), "/", "\/").'/', array_keys($this->config['renderers']));
                        if ($grep) {
                            $renderClass = $this->config['renderers'][$grep[0]];
                            break 2;
                        }
                    }
                }
            }
        } else {
            $renderClass = array_shift($this->config['renderers']);
        }
		if (isset($renderClass)) {
			require_once($renderClass);
			$renderer = new PHPRestSQLRenderer();
			$renderer->render($this);
		} else {
			$this->notAcceptable();
			exit;
		}
    }
        
    /**
     * Send a HTTP 201 response header.
     */
    function created($url = FALSE) {
        header('HTTP/1.0 201 Created');
        if ($url) {
            header('Location: '.$url);   
        }
    }
    
    /**
     * Send a HTTP 201 response header.
     */
    function accepted() {
        header('HTTP/1.0 202 Accepted');
    }

    /**
     * Send a HTTP 204 response header.
     */
    function noContent() {
        header('HTTP/1.0 204 No Content');
    }

    /**
     * Send a HTTP 301 response header.
     */
    function redirect($url = FALSE) {
        if ($url) {
            header('Location: '.$url);   
        }
    }
    
    /**
     * Send a HTTP 400 response header.
     */
    function badRequest() {
        header('HTTP/1.0 400 Bad Request');
    }
    
    /**
     * Send a HTTP 401 response header.
     */
    function unauthorized($realm = 'Rest') {
        $this->user['userid'] = false;
	$this->user['role'] = 0;
	$this->user['active'] = 0;
	$_SESSION['user'] = $this->user;
        //header('WWW-Authenticate: Basic realm="'.$realm.'"');
        //#header('HTTP/1.0 401 Unauthorized');
    }
    
    /**
     * Send a HTTP 404 response header.
     */
    function notFound() {
        header('HTTP/1.0 404 Not Found');
    }
    
    /**
     * Send a HTTP 405 response header.
     */
    function methodNotAllowed($allowed = 'GET, HEAD') {
        header('HTTP/1.0 405 Method Not Allowed');
        header('Allow: '.$allowed);
    }
    
    /**
     * Send a HTTP 406 response header.
     */
    function notAcceptable() {
        header('HTTP/1.0 406 Not Acceptable');
        echo join(', ', array_keys($this->config['renderers']));
    }
    
    /**
     * Send a HTTP 411 response header.
     */
    function lengthRequired() {
        header('HTTP/1.0 411 Length Required');
    }
    
    /**
     * Send a HTTP 500 response header.
     */
    function internalServerError() {
        header('HTTP/1.0 500 Internal Server Error');
    }
    
}

