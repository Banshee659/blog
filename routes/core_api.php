<?php

$api_response_code = array(
    0 => array('HTTP Response' => 400, 'Message' => 'Unknown error'),
    1 => array('HTTP Response' => 200, 'Message' => 'Success'),
    2 => array('HTTP Response' => 403, 'Message' => 'HTTPS required'),
    3 => array('HTTP Response' => 401, 'Message' => 'Authentication required'),
    4 => array('HTTP Response' => 401, 'Message' => 'Authentication failed'),
    5 => array('HTTP Response' => 404, 'Message' => 'Invalid request'),
    6 => array('HTTP Response' => 400, 'Message' => 'Invalid response format'),
    7 => array('HTTP Response' => 404, 'Message' => 'No record found'),
    8 => array('HTTP Response' => 401, 'Message' => 'Invalid client'),
    9 => array('HTTP Response' => 400, 'Message' => 'Invalid request data'), // This is really a security exception
    10 => array('HTTP Response' => 200, 'Message' => 'Record not updated since specified date'),
    11 => array('HTTP Response' => 422, 'Message' => 'Member not eligible')

);

$http_response_code = array(
    200 => 'OK',
    400 => 'Bad Request',
    401 => 'Unauthorised',
    403 => 'Forbidden',
    404 => 'Not Found',
    422 => 'Not Eligible'
);
