<?php

if(empty($username)) {
	$username = 'julioelpoeta';
}

// Obviously, this'll be called by ajax, just here as a reference
$json = file_get_contents('http://' . $_SERVER['HTTP_HOST'] . '/ajax/?username=' . $username);	// /ajax/$username would have also worked

$response = array('response_type' => 'json', 'response' => $json);
return $response;

// To create a view just add it to the views folder and assign the name string to a variable called $view
// If you need to pass any data to the view, you can use a variable called $view_data, it should be an array and
// all the keys of the array will be available at the view as $'key'.
