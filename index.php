<?php
require_once('config.inc.php'); //reddit and hcaptcha config

ini_set('session.save_path', $config['session_path']);
ini_set('session.gc_maxlifetime', $config['session_duration']);
    session_start( [
        'cookie_path' => '/',
        'cookie_lifetime' => intval($config['session_duration']),
        'cookie_secure' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'lax',
    ] );


$now = time();
if (isset($_SESSION['discard_after']) && $now > $_SESSION['discard_after']) {
    // this session has worn out its welcome; kill it and start a brand new one
    session_unset();
    session_destroy();
    session_start( [
        'cookie_path' => '/',
        'cookie_lifetime' => intval($config['session_duration']),
        'cookie_secure' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'lax',
    ] );

}

// either new or old, it should live at most for another hour
if ( isset($_SESSION['contributor'] ) ) {
    $_SESSION['discard_after'] = $now + $config['session_duration'];
} else {
    $_SESSION['discard_after'] = $now + $config['session_duration_user'];
}

include('database.inc.php'); //database connection
include('Parsedown.php');

$Parsedown = new Parsedown();
function uniqidReal($lenght = 13) {
    // uniqid gives 13 chars, but you could adjust it to your needs.
    if (function_exists("random_bytes")) {
        $bytes = random_bytes(ceil($lenght / 2));
    } elseif (function_exists("openssl_random_pseudo_bytes")) {
        $bytes = openssl_random_pseudo_bytes(ceil($lenght / 2));
    } else {
        throw new Exception("no cryptographically secure random function available");
    }
    return substr(bin2hex($bytes), 0, $lenght);
}

function getToken($length){
    global $conn;
    $token = "";
    $codeAlphabet = "";
    //$codeAlphabet.= "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    $codeAlphabet.= "abcdefghijklmnopqrstuvwxyz";
    $codeAlphabet.= "0123456789";
    $max = strlen($codeAlphabet);
    $tt = 1;
    while ($tt > 0) {
        $token = "";
        for ($i=0; $i < $length; $i++) {
            $token .= $codeAlphabet[random_int(0, $max-1)];
        }
        $sql = "SELECT count(*) FROM gamekeys WHERE hash = '".$token."';";
        $result = $conn->query($sql);
        $data =  $result->fetch_assoc();
        $tt=0;
        if (isset($data['count(*)'])) $tt = $data['count(*)'];

    }
    return $token;
}
$state = uniqidReal();

$USERIP = $_SERVER['REMOTE_ADDR'];
if ( strrpos( $USERIP, ':' ) ) {  // convert IPv6 to /64 subnet
    $splitip = explode(':', $USERIP);
    $USERIP = $splitip[0] . ':' . $splitip[1] . ':' . $splitip[2] . ':' . $splitip[3] . '::0/64';
}

if ( isset($_GET['path']) ) {
    $path = explode('/',strtolower($_GET['path']));

    if ( $path[0] == "a" ) {
        http_response_code(500);
        die();
    }
    if ( $path[0] == "logout" ) {
        $_SESSION['loggedin'] = false;
        header("Location: https://".$_SERVER['SERVER_NAME']."/");
        die("Redirect");
    }
    if ( $path[0] == "login" ) {
        if ( isset($_GET["code"]) AND preg_match('/[^A-Za-z0-9_-]/', $_GET['code'])) { 
            $code = $_GET['code'];
        }
        $authorizeUrl = 'https://ssl.reddit.com/api/v1/authorize';
        $accessTokenUrl = 'https://ssl.reddit.com/api/v1/access_token';

        $userAgent = 'keyshare.link/0.1 by dgc1980';

        require("OAuth2/Client.php");
        require("OAuth2/GrantType/IGrantType.php");
        require("OAuth2/GrantType/AuthorizationCode.php");

        $client = new OAuth2\Client($clientId, $clientSecret, OAuth2\Client::AUTH_TYPE_AUTHORIZATION_BASIC);
        $client->setCurlOption(CURLOPT_USERAGENT,$userAgent);

        if (!isset($_GET["code"]))
        {
            if ( isset($_SERVER['HTTP_REFERER']) ) {
            $_SESSION['redirect_uri'] = $_SERVER['HTTP_REFERER'];
            } else { $_SESSION['redirect_uri'] = 'https://keyshare.link'; }
            $_SESSION['state'] = md5($state);
            $authUrl = $client->getAuthenticationUrl($authorizeUrl, $redirectUrl, array("scope" => "identity", "state" => md5($state)));
            header("Location: ".$authUrl);
            die("Redirect");
        }
        else
        {
            if ( !isset( $_REQUEST['state']) or !isset( $_SESSION['state'] ) ) {
                if ( isset( $_SESSION['redirect_uri'] ) ) { header("Location: ".$_SESSION['redirect_uri']); } else { header("Location: /"); }
                die();
            }
	    if ( $_REQUEST['state'] != $_SESSION['state'] ) {
                header("Location: ".$_SESSION['redirect_uri']);
                die("Redirect");
            }
            $params = array("code" => $_GET["code"], "redirect_uri" => $redirectUrl);
            $response = $client->getAccessToken($accessTokenUrl, "authorization_code", $params);
            $accessTokenResult = $response["result"];
            $client->setAccessToken($accessTokenResult["access_token"]);
            $client->setAccessTokenType(OAuth2\Client::ACCESS_TOKEN_BEARER);
            $response = $client->fetch("https://oauth.reddit.com/api/v1/me.json");

            if ( $response['code'] == 200 ) {

                $_SESSION['username'] = $response['result']['name'];
                $_SESSION['loggedin'] = true;
                $_SESSION['karma_link'] = $response['result']['link_karma'];
                $_SESSION['karma_comment'] = $response['result']['comment_karma'];
                $_SESSION['account_age'] = intval((time() - intval($response['result']['created_utc'])) / 86400);
                

                $sql = "SELECT count(*) from gamekeys where reddit_owner = '".addslashes($_SESSION['username'])."' AND claimed = 1;";
                $result = $conn->query($sql);
                $data =  $result->fetch_assoc();
                if ($data['count(*)'] > 10) {
                    $_SESSION['contributor'] = 1;
                }

                if ( isset($_SESSION['contributor'] ) ) {
                    $_SESSION['discard_after'] = $now + $config['session_duration'];
                } else {
                    $_SESSION['discard_after'] = $now + $config['session_duration_user'];
                }

                if ( $response['result']['is_suspended'] == "1") { $_SESSION['karma_link'] = 0; $_SESSION['karma_comment'] = 0; $_SESSION['account_age'] =0; }

            } else {
            }
        }
        if ( !isset($_SESSION['redirect_uri']) or $_SESSION['redirect_uri']=="") {
            header("Location: https://keyshare.link/");
        } else {
            header("Location: ".$_SESSION['redirect_uri']);
        }
        $_SESSION['redirect_uri'] = "";
        die("Redirect");
    } else {
    }

} else {
   $path[0] = "";
}
unset($_SESSION['redirect_uri']);

$loggedin = false;
if (isset($_SESSION['loggedin'])) {
    $loggedin = $_SESSION['loggedin'];
}
if ( $loggedin != true ) { $loginurl = "/login"; }

if ( $loggedin == true) {
            $sql2 = "SELECT * FROM accountprefs WHERE username = '".$_SESSION['username']."';";
            $result2 = $conn->query($sql2);
            $row2 =  $result2->fetch_assoc();
                if ( !isset( $row2['username']) ) {
                    //if ( $row2['username'] != $_SESSION['username']) {
                        $sql = "INSERT INTO accountprefs (username,optout) VALUES ('".$_SESSION['username']."',0);";
                        $result = $conn->query($sql);
                    //}
                }
            $sql2 = "SELECT * FROM accountprefs WHERE username = '".$_SESSION['username']."';";
            $result2 = $conn->query($sql2);
            $row2 =  $result2->fetch_assoc();
 		    if ( $row2['username'] == $_SESSION['username']) {
                if ( !strrpos("   ".$row2['ip'],$USERIP) or $row2['ip'] == null) {
                    if ( $row2['ip'] == null ) {
  					    $new_ips = $USERIP;
                    } else {
  					    $new_ips = $row2['ip'] . "\n" . $USERIP;
                    }
                    $stmt = $conn->prepare("UPDATE accountprefs SET ip = ? WHERE username = ?;");
                	$stmt->bind_param("ss", $new_ips ,$_SESSION['username']);
                	//$reportreason = $_POST['ReportReason'];
                	$stmt->execute();    
                }
            }
}


if ( $path[0] == "profile" and isset($path[1]) and $path[1] == "delete" and $loggedin == true) {
    if (!preg_match('/[^A-Za-z0-9]/', $path[2])) // '/[^a-z\d]/i' should also work.
    {

        $stmt = $conn->prepare("SELECT * FROM gamekeys WHERE hash = ?;");
        $stmt->bind_param("s", $path[2]);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        if ( $row['claimed'] == 0 and $_SESSION['username'] == $row['reddit_owner'] ) {
            $stmt = $conn->prepare("DELETE FROM gamekeys WHERE hash = ?;");
            $stmt->bind_param("s", $path[2]);
            $stmt->execute();
        }
    }
    header("Location: /profile" );
    die("Redirect");
}

if ( $path[0] == "report") {
    if ($loggedin == true) {
        if (!preg_match('/[^A-Za-z0-9]/', $path[1])) {
            $stmt = $conn->prepare("SELECT * FROM gamekeys WHERE hash = ?;");
            $stmt->bind_param("s", $path[1]);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            if ( $_SESSION['username'] == $row['reddit_who']){
                $stmt = $conn->prepare("UPDATE gamekeys SET reported = 1, reportreason = ? WHERE hash = ?;");
                $stmt->bind_param("ss", $reportreason ,$path[1]);
                $reportreason = $_POST['ReportReason'];
                $stmt->execute();
            }
        }
    }
    header("Location: /k/".$path[1] );
    die("Redirect");
}

if ( $path[0] == "success") {
    if ($loggedin == true) {
        if (!preg_match('/[^A-Za-z0-9]/', $path[1])) {
            $stmt = $conn->prepare("SELECT * FROM gamekeys WHERE hash = ?;");
            $stmt->bind_param("s", $path[1]);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            if ( $_SESSION['username'] == $row['reddit_who']){
                $stmt = $conn->prepare("UPDATE gamekeys SET worked = 1 WHERE hash = ?;");
                $stmt->bind_param("s", $path[1]);
                $stmt->execute();
            }
        }
    }
    header("Location: /k/".$path[1] );
    die("Redirect");
}


if ( $path[0] == "profile" ) {
    if ($loggedin == true and isset($path[1])) {
        if ( $path[1] == "opt-out" or $path[1] == "opt-in") {
            $sql2 = "SELECT * FROM accountprefs WHERE username = '".$_SESSION['username']."';";
            $result2 = $conn->query($sql2);
            $row2 =  $result2->fetch_assoc();
            $v = 0; if ($path[1] == "opt-out") { $v =1; }
            if ( $row2['username'] == $_SESSION['username']) {
                $sql = "UPDATE accountprefs SET optout = ".$v." WHERE username = '".$_SESSION['username']."'";
            } else {
                $sql = "INSERT INTO accountprefs (username,optout) VALUES ('".$_SESSION['username']."',".$v.");";
            }
            $result = $conn->query($sql);
            header("Location: /profile" );
            die("Redirect");
        }
    }
}


if ( $path[0] == "newkey" ) {
    if ($loggedin == true) {
        if (isset($_POST['submit']) and $_POST['submit'] == 1) {
            $t = getToken(10);
            $stmt = $conn->prepare("INSERT INTO gamekeys (gametitle,gamekey,dateadded,captcha,reddit,reddit_owner,karma_link,karma_comment,account_age,hash,startdate) VALUES (?,?,?,?,?,?,?,?,?,?,?);");
            $stmt->bind_param("ssiiisiiisi", $value_gametitle, $value_gamekey, $value_dateadded, $value_captcha, $value_reddit, $value_reddit_owner, $value_karmalink, $value_karmacomment, $value_accountage, $value_hash, $value_startdate);
            $value_gametitle = htmlentities(substr($_POST['InputGameTitle'],0,200));
            $value_gamekey = htmlentities(substr($_POST['InputGameKey'],0,100));
            $value_dateadded = time();
            $value_captcha = 1;
            $value_reddit = 1;
            $value_reddit_owner = $_SESSION['username'];
            $value_karmalink = intval($_POST['InputKarmaLink']);
            if ( $value_karmalink > 5000 ) { $value_karmalink = 5000;}
            $value_karmacomment = intval($_POST['InputKarmaComment']);
            if ( $value_karmacomment > 5000 ) { $value_karmacomment = 5000;}
            $value_accountage = intval($_POST['InputAccountAge']);
            if ( $value_accountage > 5000 ) { $value_accountage = 5000;}
            $value_hash = $t;
            $value_startdate = 0;
            if ( isset($_POST['InputStartDate'])) {
                $value_startdate = strtotime($_POST['InputStartDate']);
            } else {
                $value_startdate = strtotime("NOW");
            }
            $stmt->execute();
            //if(!$stmt->execute()) { echo $stmt->error; die(); }

            $_SESSION['last_linkkarma'] = $value_karmalink;
            $_SESSION['last_commentkarma'] = $value_karmacomment;
            $_SESSION['last_age'] = $value_accountage;
            
            header("Location: /profile" );
            die("Redirect");
        }
    }
}



if ( $path[0] == "claim" ) {
    if ( !isset( $path[1] ) ) { die(); }
        $stmt = $conn->prepare("SELECT * FROM gamekeys WHERE hash = ?;");
        $stmt->bind_param("s", $path[1]);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt = $conn->prepare("SELECT * FROM bans WHERE username like ?;"); //changed to like due to case-insensitive
        $stmt->bind_param("s", $_SESSION['username']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($buser = $result->fetch_assoc()	) {
            if ( ( $buser['owner'] == 'global') or ( $buser['owner'] == $row['reddit_owner'] ) ) {
                http_response_code(403);
                header("Location: /restricted" );
                die("Redirect");
            }
        }

    if(isset($_POST['h-captcha-response']) && !empty($_POST['h-captcha-response'])  )
    {
          $verifyResponse = file_get_contents('https://hcaptcha.com/siteverify?secret='.$hcsecret.'&response='.$_POST['h-captcha-response'].'&remoteip='.$_SERVER['REMOTE_ADDR']);
          $responseData = json_decode($verifyResponse);
          if($responseData->success)
          {
              $s = 1;
          }
          else
          {
              $s = 0;
          }



          // block claims from same gifter within 24 hours

          $sql = "SELECT count(*) from gamekeys where reddit_who = '".addslashes($_SESSION['username'])."' AND reddit_owner = '".addslashes($row['reddit_owner'])."'AND dateclaimed > ".(time()-(86400)).";";
          $result = $conn->query($sql);
          $data =  $result->fetch_assoc();
          if ($data['count(*)'] >= 1) {
            header("Location: /too-many" );
            die("Redirect");
          }

          $sql = "SELECT count(*) from gamekeys where reddit_who = '".addslashes($_SESSION['username'])."' AND dateclaimed > ".(time()-(86400*7)).";";

          // Weekly Global Limit

          $result = $conn->query($sql);
          $data =  $result->fetch_assoc();
          if ($data['count(*)'] >= $config['weekly_limit']) {
            header("Location: /too-many" );
            die("Redirect");
          }
          $sql = "SELECT count(*) from ratelimit_ip where who = '".addslashes($USERIP)."' AND lastclaim > ".(time()-(86400*7)).";";
          $result = $conn->query($sql);
          $data =  $result->fetch_assoc();
          if ($data['count(*)'] >= $config['weekly_limit']) {
            header("Location: /too-many" );
            die("Redirect");
          }

          // Global Limit for 30 minutes
          $sql = "SELECT count(*) from ratelimit where who = '".addslashes($_SESSION['username'])."' AND lastclaim > ".(time() - $config['ratelimit_duration']).";";
          $result = $conn->query($sql);
          $data =  $result->fetch_assoc();
          if ($data['count(*)'] > 0) {
            header("Location: /too-many" );
            die("Redirect");
          }
          $sql = "SELECT count(*) from ratelimit_ip where who = '".addslashes($USERIP)."' AND lastclaim > ".(time() - $config['ratelimit_duration']).";";
          $result = $conn->query($sql);
          $data =  $result->fetch_assoc();
          if ($data['count(*)'] > 0) {
            header("Location: /too-many" );
            die("Redirect");
          }



          if ( $s == 1) {
            if (!preg_match('/[^A-Za-z0-9]/', $path[1])) // '/[^a-z\d]/i' should also work.
            {

                if ($row['claimed'] == 0 and time() > $row['startdate']) {
                    $sql = "UPDATE gamekeys SET claimed = 1, reddit_who = '".addslashes($_SESSION['username'])."', dateclaimed = ".time()." WHERE hash = '".$path[1]."';";
                    $result = $conn->query($sql);

                        $sql = "INSERT INTO ratelimit (who,lastclaim) VALUES ('".addslashes($_SESSION['username'])."',".time().");";
                        $result = $conn->query($sql);

                        $sql = "INSERT INTO ratelimit_ip (who,lastclaim) VALUES ('".addslashes($USERIP)."',".time().");";
                        $result = $conn->query($sql);
                }
                header("Location: /k/".$path[1] );
                die("Redirect");
            }
          }
     } else {
                header("Location: /k/".$path[1] );
                die("Redirect");
     }



}





?>
<head>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@4.5.2/dist/superhero/bootstrap.min.css" integrity="sha384-HnTY+mLT0stQlOwD3wcAzSVAZbrBp141qwfR4WfTqVQKSgmcgzk+oP0ieIyrxiFO" crossorigin="anonymous">
    <link rel="stylesheet" href="/custom.css">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://code.jquery.com/jquery-1.12.4.min.js" integrity="sha384-nvAa0+6Qg9clwYCGGPpDQLVpLNn0fRaROjHqs13t4Ggj3Ez50XnGQqc/r8MhnRDZ" crossorigin="anonymous"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js" integrity="sha384-B4gt1jrGC7Jh4AgTPSdUtOBvfO8shuf57BaghqFfPlYxofvL8/KUEfYiJOMMV+rV" crossorigin="anonymous"></script>
    <script src='https://www.hCaptcha.com/1/api.js' async defer></script>
    <script src='/clipboard.js'></script>
    <title>KeyShare.link - Platform for sharing unused game keys on reddit</title>
</head>

<body style="text-align: center" class="Site">


<nav class="navbar navbar-expand-lg navbar-dark bg-primary fixed-top">
  <a class="navbar-brand" href="/">KeyShare</a>

  <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarColor01" aria-controls="navbarColor01" aria-expanded="false" aria-label="Toggle navigation">
    <span class="navbar-toggler-icon"></span>
  </button>

  <div class="collapse navbar-collapse" id="navbarColor01">
    <ul class="navbar-nav mr-auto">
      <li class="nav-item active">
        <a class="nav-link" href="/">Home <span class="sr-only"></span></a>
      </li>
      <li class="nav-item active">
        <a class="nav-link" href="/top10">Top 10 <span class="sr-only"></span></a>
      </li>
    </ul>
    <ul class="navbar-nav">
<?php if ( isset($_SESSION['loggedin']) and $_SESSION['loggedin'] == true ) { ?>
    <li class="nav-item">
    <a class="nav-link" href="/profile"><?php echo $_SESSION['username']; ?></a>
      </li>
    <li class="nav-item">
      <a class="nav-link" href="/logout">Logout</a>
      </li>
<?php } else { ?>
      <li class="nav-item">
    <a class="nav-link" href="<?php echo $loginurl; ?>">Login via Reddit</a>
      </li>
<?php } ?>



    </ul>


  </div>
</nav>
<div class="container" style="margin-top: 80px;">

<?php
        $sql = "SELECT count(*) FROM gamekeys WHERE claimed = 1;";
        $result = $conn->query($sql);
        $data =  $result->fetch_assoc();
        $servedkeys=0;
        if (isset($data['count(*)'])) $servedkeys = $data['count(*)'];
        ?>
        <div class="alert alert-dismissible alert-warning">
        There has been a total of <strong><?php echo $servedkeys; ?></strong> keys submitted by awesome people and claimed by people in need.
        </div>
        <?php
?>


<?php
if ( $_SERVER['SERVER_NAME'] =='staging.keyshare.link') {
?>
<div class="alert alert-dismissible alert-danger">
  <strong>WARNING</strong><br>This is a staging server<br>Do not expect any keys to work.
</div>

<?php
}
if ( $path[0] == "top10" ) {
    $sql = "SELECT reddit_owner, count(*) FROM gamekeys WHERE claimed = 1 AND reddit_who != reddit_owner GROUP BY reddit_owner ORDER BY count(*) DESC LIMIT 10;";
    $result = $conn->query($sql);
?>
    <table class="table table-hover">
    <thead>
      <tr>
        <th scope="col">Gifter</th>
        <th scope="col">Count</th>
      </tr>
    </thead>
    <tbody>
<?php
    while( $row =  $result->fetch_assoc() ) {
        $sql2 = "SELECT * FROM accountprefs WHERE username = '".$row['reddit_owner']."';";
        $result2 = $conn->query($sql2);
        $row2 =  $result2->fetch_assoc();
        $username = $row['reddit_owner'];
        if ( isset( $row2['optout'] ) &&  $row2['optout'] == 1) { $username = "Anonymous"; }
    ?>
      <tr>
        <td>
            <?php if ( isset( $row2['optout'] ) && $row2['optout'] == 1 ) { ?>
            <?php echo $username; ?>
            <?php } else { ?>
            <a href="https://reddit.com/u/<?php echo $username; ?>" target="_blank">u/<?php echo $username; ?></a>
            <?php } ?>
        </td>
        <td><?php echo $row['count(*)']; ?></td>
      </tr>
    <?php
    }
    ?>
    </tbody>
    </table>
    <?php
}



if ( $path[0] == "newkey" ) {
    if ($loggedin == true) {
?>
<form action="/newkey/" method="POST">
  <fieldset>
    <div class="form-group">
      <label for="InputGameTitle" class="form-label mt-4">Game Title</label>
      <input type="GameTitle" class="form-control" id="InputGameTitle" name="InputGameTitle" placeholder="Game Title" maxlength="200">
    </div>

    <div class="form-group">
      <label for="InputGameKey" class="form-label mt-4">Game Key, separate multiple keys with a space</label>
      <input type="GameKey" class="form-control" id="InputGameKey" name="InputGameKey" placeholder="ABCDE-12345-ABCDE" maxlength="100">
    </div>

    <div class="form-group">
      <label for="InputStartDate" class="form-label mt-4">Start Date/Time for Giveaway "+30 minutes", "+1 day", "10am Feb 20 2022 GMT", Maximum of 1 Month in the future.</label>
      <input type="StartDate" class="form-control" id="InputStartDate" name="InputStartDate" placeholder="NOW">
    </div>

    <div class="form-group">
      <label for="InputKarmaLink" class="form-label mt-4">Minimum Link Karma (0 is disabled) - Max 5000</label>
      <input type="KarmaLink" class="form-control" id="InputKarmaLink" name="InputKarmaLink" placeholder="0" <?php if (isset($_SESSION['last_linkkarma'])) { echo "value='".$_SESSION['last_linkkarma']."'"; } else { echo "value='200'"; } ?>>
    </div>

    <div class="form-group">
      <label for="InputKarmaComment" class="form-label mt-4">Minimum Comment Karma (0 is disabled) - Max 5000</label>
      <input type="KarmaComment" class="form-control" id="InputKarmaComment" name="InputKarmaComment" placeholder="0"<?php if (isset($_SESSION['last_commentkarma'])) { echo "value='".$_SESSION['last_commentkarma']."'"; } else { echo "value='200'"; } ?>>
    </div>

    <div class="form-group">
      <label for="InputAccountAge" class="form-label mt-4">Minimum Account Age is Days (0 is disabled) - Max 5000<br>I would suggest having this at a reasonable value due to people creating throwaway accounts to claim them</label>
      <input type="AccountAge" class="form-control" id="InputAccountAge" name="InputAccountAge" placeholder="0" <?php if (isset($_SESSION['last_age'])) { echo "value='".$_SESSION['last_age']."'"; } else { echo "value='365'"; } ?>>
    </div>

    <button type="submit" class="btn btn-primary" name="submit" value="1">Submit</button>
  </fieldset>
</form>
<?php
    //print_r($_POST);
    }
}

if ( $path[0] == "too-many" ){
?>
Thank you for your interest in this key<br>
But unfortunately there is a limit of 1 key per 30 minutes<br>
There is also a limit of 1 key per 24 hours from the same submitter<br>
Or you may have hit a site-wide limit of weekly claims<br>
To give everyone a fair chance to get keys.
<?php
}


if ( $path[0] == "restricted" ){
?>
unfortunately you have been restricted from this giveaway or site.<br>
<?php
}


if ( $path[0] == "" ) {
    echo $Parsedown->text(file_get_contents('about.md')) ;
}
if ( $path[0] == "profile" and ( isset( $path[1]) and $path[1] == 'banned') ){
    if ($loggedin == true) {
        if ( isset( $_POST['removeban'] ) ) {
            $stmt = $conn->prepare("DELETE FROM bans WHERE id = ? AND owner = ?;");
            $stmt->bind_param("ss", $_POST['removeban'], $_SESSION['username']);
            $stmt->execute();
        }
        if ( isset( $_POST['banwho'] ) ) {
            $stmt = $conn->prepare("INSERT INTO bans (username,owner,reason) VALUES (?,?,?);");
            $stmt->bind_param("sss", $_POST['banwho'], $_SESSION['username'], $_POST['banreason']);
            $stmt->execute();
        }


        ?>


    <a href="/profile">Unclaimed Keys</a> | <a href="/profile/claimed">Claimed Keys </a> | <a href="/profile/banned">Banned Users </a>
    <table class="table table-hover">
    <thead>
      <tr>
        <th scope="col">User</th>
        <th scope="col">Reason</th>
        <th scope="col">Remove</th>
      </tr>
    </thead>
    <tbody>
<?php
        $sql = "SELECT * FROM bans WHERE owner = '".$_SESSION['username']."';";
        $result = $conn->query($sql);
        while ( $row = $result->fetch_assoc() ) {

            ?>
    <tr class="table-active">
        <th scope="row"><?php echo $row['username']; ?></th>
        <td><?php echo $row['reason']; ?></td>
        <td><form method=post><input type=submit value='remove'><input type=hidden name=removeban value='<?php echo $row['id']; ?>'></form></td>
    </tr>
    <?php

        }
    ?>
        </tbody>
        </table>

        <form method=post>
        Username: <input name='banwho'> Reason? <input name='banreason'> <input type='submit' value='Ban User'>
        </form>

    <?php
        
    }

}
if ( $path[0] == "profile" and (( !isset($path[1]) or $path[1] == '') or $path[1] == 'claimed')) {
//if ( $path[0] == "profile" ) {
    if ($loggedin == true) {

        ?>

    <a href="/profile">Unclaimed Keys</a> | <a href="/profile/claimed">Claimed Keys </a> | <a href="/profile/banned">Banned Users </a>
    <table class="table table-hover">
    <thead>
      <tr>
        <th scope="col">Title</th>
        <th scope="col">Key</th>
        <th scope="col">Karma/Comment/Age</th>
        <th scope="col">Claimed</th>
        <th scope="col">Start Date</th>
        <th scope="col">Link</th>
        <th scope="col">Delete</th>
        <th scope="col">Clipboard</th>
      </tr>
    </thead>
    <tbody>
<?php
        //default unclaimed
        $sql = "SELECT * FROM gamekeys WHERE reddit_owner = '".$_SESSION['username']."' AND claimed = 0;";
        if ( isset($path[1]) and $path[1] == "claimed") {
            $sql = "SELECT * FROM gamekeys WHERE reddit_owner = '".$_SESSION['username']."' AND claimed = 1 ORDER BY dateclaimed DESC;";
        }
$result = $conn->query($sql);
$kn=0;
while ( $row = $result->fetch_assoc() ) {
$kn++;
?>
    <tr class="table-active">
        <th scope="row"><?php echo $row['gametitle']; ?></th>
        <td><?php echo $row['gamekey']; ?></td>
        <td><?php echo $row['karma_link']."/".$row['karma_comment']."/".$row['account_age']; ?></td>
        <td><a href="https://reddit.com/u/<?php echo $row['reddit_who']; ?>" target="_blank"><?php echo $row['reddit_who']; ?></a></td>

        <td><?php
            if($row['startdate'] > 0 ) {
                echo gmdate("M d Y H:i:s",$row['startdate']);
            } else { echo "&nbsp;" ;}

        ?></td>

<?php /*
        <td><input value="https://keyshare.link/k/<?php echo $row['hash']; ?>" class="form-control form-control-sm"></td>
*/ ?>
        <td><a href="https://<?php echo $_SERVER['SERVER_NAME']; ?>/k/<?php echo $row['hash']; ?>">https://<?php echo $_SERVER['SERVER_NAME']; ?>/k/<?php echo $row['hash']; ?></a></td>
        <td><?php
        if ( !isset($path[1]) or $path[1] != "claimed") {
            ?>
            <form action="/profile/delete/<?php echo $row['hash']; ?>" method="POST">
            <input type="submit" name="delete" value="delete">
            </form>
            <?php
        } else { echo "&nbsp;"; }
        ?></td>

        <td>
            <p hidden id="k<?php echo $kn;?>">[<?php echo $row['gametitle']; ?>](https://<?php echo $_SERVER['SERVER_NAME']; ?>/k/<?php echo $row['hash']; ?>)</p><p hidden id="l<?php echo $kn;?>"><?php echo $row['gametitle']; ?> - https://<?php echo $_SERVER['SERVER_NAME']; ?>/k/<?php echo $row['hash']; ?></p>
            <input type=submit onclick="copyToClipboard('#k<?php echo $kn;?>')" value="Reddit Link"></input>
            <input type=submit onclick="copyToClipboard('#l<?php echo $kn;?>')" value="Reddit Text"></input>
        </td>
    </tr>
<?php
    }
?>
    </tbody>
</table>
<a href="/newkey">Create New Giveaway</a><br><br>
<?php
        $sql2 = "SELECT * FROM accountprefs WHERE username = '".$_SESSION['username']."';";
        $result2 = $conn->query($sql2);
        $row2 =  $result2->fetch_assoc();
        if ( $row2['optout'] == 1) { ?>
            <a href="/profile/opt-in">Opt-in to displaying username in Top10</a><br><br>
        <?php } else { ?>
            <a href="/profile/opt-out">Opt-out to displaying username in Top10</a><br><br>
        <?php } 



    }



} else {

    if ( $path[0] == "k" ) {
        if ( !isset( $path[1]) ) { die(); }
        if (!preg_match('/[^A-Za-z0-9]/', $path[1])) // '/[^a-z\d]/i' should also work.
        {
            $stmt = $conn->prepare("SELECT * FROM gamekeys WHERE hash = ?;");
            $stmt->bind_param("s", $path[1]);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            if ( $row && $row['hash'] == $path[1]) {

                if ( $row['reddit_owner'] == "dgc1980" ) { $claimtime = 900; } else { $claimtime = 900; }

                if ( time() < $row['startdate'] ) {
                    echo "<center><h1>this giveaway has not yet started</h1></center>";
                } elseif (isset($_SESSION['username']) and (($row['reddit_who'] == $_SESSION['username'] and $row['claimed'] == 1) or ( $loggedin AND (time() > ($row['dateclaimed'] + 1800 ) AND time() < ($row['dateclaimed'] + 7200 ) AND $row['dateclaimed'] > 1641324520 )))) {
                    echo "<center>Here is your gifted key for,<br><h1><b>".$row['gametitle']."</b></h1>";
                    if ( $row['reddit_who'] != $_SESSION['username'] ) {
                        echo "<br><i>Warning, this key was claimed by another user<br>and may already be claimed<br>as a security measure to prevent reselling<br>the key has been revealed</i><br>";
                    } else {
                        echo "<br><b>WARNING<br>THIS KEY WILL BE REVEALED TO PUBLIC AFTER 30 MINUTES</b><br>";
                    }
                    $keys = explode(' ',$row['gamekey']);
                    foreach ( $keys as $k ) {
                        if ( $k != "") {
                            echo '<input value="'.$k.'" class="form-control form-control-sm" style="width: 300px; text-align: center;">';
                            if (   preg_match('/[\w\d]{5,5}-[\w\d]{5,5}-[\w\d]{5,5}-[\w\d]{5,5}-[\w\d]{5,5}/', $k)    ) {
                                echo "  this looks like a Microsoft Key <a href='https://account.microsoft.com/billing/redeem' target='_blank'>Click Here to Redeem</a>";
                            } elseif (   preg_match('/[\w\d]{5,5}-[\w\d]{5,5}-[\w\d]{5,5}/', $k)    ) {
                                echo "  this looks like a Steam Key <a href='https://store.steampowered.com/account/registerkey?key=".$k."' target='_blank'>Click Here to Redeem</a>";
                            }
                            if (   preg_match('/^[\w\d]{18,18}$/', $k)    ) {
                                echo "  this looks like a GOG Key <a href='https://gog.com/redeem/".$k."' target='_blank'>Click Here to Redeem</a>";
                            }
                            echo '<br>';
                        }
                    }
                    echo "<br><br>This key was kindly gifted by <a href='https://reddit.com/u/".$row['reddit_owner']."' target='_blank'>u/".$row['reddit_owner']."</a>";
                    echo "<br><br>please remember to thank the user who shared this key by replying to their comment.";

                    if ($_SESSION['username'] == $row['reddit_who'] and 1 == 0) {
                        if ( ($row['reported'] == 0 and $row['worked'] == 0) AND time() < ($row['dateclaimed'] + 7200 )) {
                            ?> <br><br>

                            Did this key work?<br>
                            <form action="/success/<?php echo $path[1]; ?>" method="POST"><input type="submit" class="btn btn-success" value="Yes &#128513;"></form><form action="/report/<?php echo $path[1]; ?>" method="POST"><input type="submit" class="btn btn-danger" value="No &#128546;">
                            <input type="ReportReason" class="form-control" id="ReportReason" name="ReportReason" placeholder="reason for choosing No" style="width: 200px;"></form>
                            <?php
                        } else {
                            if ( $row['reported'] == 1) { ?>
                                <div class="alert alert-dismissible alert-danger">
                                  This key has been reported we not working.
                                </div>
                            <?php } elseif ( $row['worked'] == 1 ) { ?>
                                <div class="alert alert-dismissible alert-success">
                                  This key has been marked as working.
                                </div>
                            <?php }
                        }
                    }

                } elseif ($row['claimed'] == 1 and (time() < ($row['dateclaimed'] + 1800 ))) {
                    echo "<h1>key already claimed by another user</h1><br>it has not been 30 minutes since this key was claimed<br>please check back again soon to see if the user has redeemed the key.";
                } elseif ($row['claimed'] == 1 and (time() > ($row['dateclaimed'] + 7200 ))) {
                    echo "key already claimed by another user<br>and is past the security reveal time.";
                } else {
                    if ($row['reddit'] == 1 and $loggedin == false) {
                        echo "this key requires you to be logged in to claim it<br><br><a href='/login'>Login Here</a>";
                    } else {
                        $aok = 1;
                        if ( $row['karma_link'] > 0 AND intval($_SESSION['karma_link']) < $row['karma_link'] ) { $aok = 0; }
                        if ( $row['karma_comment'] > 0 AND intval($_SESSION['karma_comment']) < $row['karma_comment']) { $aok = 0; }
                        if ( $row['account_age'] > 0 AND intval($_SESSION['account_age']) < $row['account_age']) { $aok = 0; }
                        if ( $aok == 1) {
                        echo "<h1>".$row['gametitle']."</h1>";
                        echo "Please complete the Captcha below to claim this key<br><br>The key may still be claimed by another user within this time<br><br>This is a First Come First Served entry.<br><br>";
                        ?>
                        <form action="/claim/<?php echo $path[1]; ?>" method="POST">
                        <div class="h-captcha" data-sitekey="<?php echo $hcsiteid; ?>"></div>
                        <br><input type="submit" name="submit" value="CLAIM" class="btn btn-primary">
                        </form>
                        <?php
                        } else {
                            echo "Unfortunately your reddit account does not meet the requirements set by the gifter";
                        }
                    }
                }
            } else { echo "invalid code"; }
        } else { echo "invalid code"; }
    }
}

?>

</div>

<div id="footer" style="color: black;">
created by <a href="https://reddit.com/u/dgc1980" target="_blank">u/dgc1980</a> for the caring users of <a href="https://reddit.com/r/GameDeals" target="_blank">r/GameDeals</a> who love to share their game keys - <small><a href='https://github.com/dgc1980/keyshare.link'>source code</a></small><br>
</div>
<?php /*
*/ ?>

<script src="/cookie/load.js"></script>
</body>
<?php
?>
