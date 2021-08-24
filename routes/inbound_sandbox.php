<?php
require_once('/var/app/current/global/core/jplSite.php');

function revSPORTapiRequest(string $api, string $endpoint, int $id = null, array $parameters = [])
{
  //Set timezone to the Server's timzone, so the digest's time matches up.
  date_default_timezone_set('Australia/Sydney');

  //Replace these with
  //revdemoclub
  $apiKey = '';
  $secretKey = '';
  $method = 'GET';
  //$url = 'https://lz-1.revolutionise.com.au/' . $api . '/' . $endpoint . '/';
  $url = 'https://lz-1-staging.revolutionise.com.au/' . $api . '/' . $endpoint . '/';
  //$url = 'https://lz-1.rev.local/' . $api . '/' . $endpoint . '/';

  if (empty($id) === false)
  {
    $url .= $id;
  }

  $now = time();
  $date = date('d M Y H:i:s', $now);
  $URLFriendlyDate = str_replace(' ', '', $date);
  $nonce = rand(100000, 999999); // generated randomly

  // Build the digest to send
  $digest = base64_encode(hash_hmac('sha256', $method . "+/" . $endpoint . "/" . (empty($id) ? '' : $id) . "+$URLFriendlyDate+$nonce", $secretKey));
  $x = array(
    'api_key' => $apiKey,
    'date' => $date,
    'nonce' => $nonce,
    'digest' => $digest
  );

  $postfields = array_merge($x, $parameters);

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); //make sure it returns a response
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // allow https verification if true
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // allow https verification if true
  curl_setopt($ch, CURLOPT_POST, true); //tell it we are posting
  curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query_for_curl($postfields)); //tell it what to post
  //curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json')); //response comes back as json
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: multipart/form-data')); //response comes back as json
  $response = curl_exec($ch);


  // Check if any error occurred
  if (!curl_errno($ch))
  {
    $info = curl_getinfo($ch);
  }
  // Close handle
  curl_close($ch);

  //$response = json_decode($response, true);

  return $response;
}

//if($_POST['natID'] && $_POST['pcID'] && $_POST['dates'] && $_POST['prices'])
if($_POST['natID'])
{
  $datesPrices = [
    /*'2020-04-20' => '100',
    '2020-04-20' => '200', //duplicate
    '2600-04-21' => '110', //invalid date
    '2020-02-13' => '110', //duplicate with member's date.*/
    $_POST['dates'] => $_POST['prices'],
  ];

  http_build_query_for_curl($datesPrices);

  /*$return = revSPORTapiRequest('inbound/v1', 'temporary-membership', null, [
    'nationalID' => $_POST['natID'],
    'paymentClassID' => $_POST['pcID'], //64307
    'datesPrices' => $datesPrices,
  ]);*/

  $return = revSPORTapiRequest('inbound/v1', 'member', null, [
    'firstname' => 'Fred',
    'surname' => 'SWINTON',
    'paymentClassID' => 91508, //64307
    'dateOfBirth' => '1999-01-01',
    'addressCountry' => 'Australia',
    'addressState' => 'QLD',
    'addressPostCode' => '3030',
    'gender' => 'M',
    'email' => '1@1.com',
    'result_limit' => '',
    'result_offset' => 0,
  ]);

  echo  ('
  <ol class="code">
    <li><code> '.$return.' </code> </li>
  </ol>
    ');
}
else
{
  ?>
  <form method="POST" action="<?php echo(JPL_URL); ?>/victor/_test_inbound_better.php">
    <input type="number" name="natID" placeholder="national ID"/>
    <input type="number" name="pcID" placeholder="temporary payment class ID"/>
    <input type="text" name="dates" placeholder="yyyy-mm-dd"/>
    <input type="number" name="prices" placeholder="price"/>
    <br /> <br /> <button type="submit">Test</button>
  </form>
  <?php
}

function http_build_query_for_curl( $arrays, &$new = array(), $prefix = null ) {

  if ( is_object( $arrays ) ) {
    $arrays = get_object_vars( $arrays );
  }

  foreach ( $arrays AS $key => $value ) {
    $k = isset( $prefix ) ? $prefix . '[' . $key . ']' : $key;
    if ( is_array( $value ) OR is_object( $value )  ) {
      http_build_query_for_curl( $value, $new, $k );
    } else {
      $new[$k] = $value;
    }
  }

  return $new;
}
?>


