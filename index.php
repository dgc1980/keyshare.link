<?php
session_start();
include('database.inc.php'); //database connection
include('Parsedown.php');
$Parsedown = new Parsedown();
require_once('config.inc.php'); //reddit and hcaptcha config
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
        $sql = "SELECT COUNT(*) FROM gamekeys WHERE 'hash' = '".$token."';";
        $result = $conn->query($sql);
        $data =  $result->fetch_assoc();
        $tt=0;
        if (isset($data['count(*)'])) $tt = $data['count(*)'];

    }
    return $token;
}
$state = uniqidReal();



if ( isset($_GET['path']) ) {
    $path = explode('/',strtolower($_GET['path']));

    if ( $path[0] == "logout" ) {
        $_SESSION['loggedin'] = false;
        header("Location: https://keyshare.link/");
        die("Redirect");
    }
    if ( $path[0] == "login" ) {
        if ( isset($_GET["code"]) AND preg_match('/[^A-Za-z0-9_-]/', $_GET['code'])) { 
            $code = $_GET['code'];
        }
        $redirectUrl = "https://keyshare.link/login";
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
            $_SESSION['redirect_uri'] = $_SERVER['HTTP_REFERER'];
            $_SESSION['state'] = md5($state);
            $authUrl = $client->getAuthenticationUrl($authorizeUrl, $redirectUrl, array("scope" => "identity", "state" => md5($state)));
            header("Location: ".$authUrl);
            die("Redirect");
        }
        else
        {
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
                $_SESSION['loggedin'] = true;
                $_SESSION['username'] = $response['result']['name'];
                $_SESSION['karma_link'] = $response['result']['link_karma'];
                $_SESSION['karma_comment'] = $response['result']['comment_karma'];
                $_SESSION['account_age'] = intval((time() - intval($response['result']['created_utc'])) / 86400);
            } else {
            }
        }
        if ($_SESSION['redirect_uri']=="") {
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

if ( $path[0] == "newkey" ) {
    if ($loggedin == true) {
        if (isset($_POST['submit']) and $_POST['submit'] == 1) {
            $t = getToken(8);
            $stmt = $conn->prepare("INSERT INTO gamekeys (gametitle,gamekey,dateadded,captcha,reddit,reddit_owner,karma_link,karma_comment,account_age,hash) VALUES (?,?,?,?,?,?,?,?,?,?);");
            $stmt->bind_param("ssiiisiiis", $value_gametitle, $value_gamekey, $value_dateadded, $value_captcha, $value_reddit, $value_reddit_owner, $value_karmalink, $value_karmacomment, $value_accountage, $value_hash);
            $value_gametitle = htmlentities($_POST['InputGameTitle']);
            $value_gamekey = htmlentities($_POST['InputGameKey']);
            $value_dateadded = time();
            $value_captcha = 1;
            $value_reddit = 1;
            $value_reddit_owner = $_SESSION['username'];
            $value_karmalink = intval($_POST['InputKarmaLink']);
            $value_karmacomment = intval($_POST['InputKarmaComment']);
            $value_accountage = intval($_POST['InputAccountAge']);
            $value_hash = $t;
            $stmt->execute();

            $_SESSION['last_linkkarma'] = $value_linkcomment;
            $_SESSION['last_commentkarma'] = $value_karmacomment;
            $_SESSION['last_age'] = $value_accountage;
            
            header("Location: /profile" );
            die("Redirect");
        }
    }
}





if ( $path[0] == "claim" ) {

    if(isset($_POST['h-captcha-response']) && !empty($_POST['h-captcha-response']))
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

          $sql = "SELECT count(*) from ratelimit where who = '".addslashes($_SESSION['username'])."' AND lastclaim > ".(time()-300).";";
          $result = $conn->query($sql);
          $data =  $result->fetch_assoc();
          if ($data['count(*)'] > 0) {
            header("Location: /too-many" );
            die("Redirect");
          }
          if ( $s == 1) {
            if (!preg_match('/[^A-Za-z0-9]/', $path[1])) // '/[^a-z\d]/i' should also work.
            {

                $stmt = $conn->prepare("SELECT * FROM gamekeys WHERE hash = ?;");
                $stmt->bind_param("s", $path[1]);
                $stmt->execute();
                $result = $stmt->get_result();

                $row = $result->fetch_assoc();

                if ($row['claimed'] == 0) {
                    $sql = "UPDATE gamekeys SET claimed = 1, reddit_who = '".addslashes($_SESSION['username'])."', dateclaimed = ".time()." WHERE hash = '".$path[1]."';";
                    $result = $conn->query($sql);


                    $sql = "SELECT count(*) from ratelimit where who = '".addslashes($_SESSION['username'])."';";
                    $result = $conn->query($sql);
                    $data =  $result->fetch_assoc();
                    if ( $data['count(*)'] > 0 ) {
                        $sql = "UPDATE ratelimit SET lastclaim = ".time()." WHERE who = '".addslashes($_SESSION['username'])."';";
                        $result = $conn->query($sql);
                    } else {
                        $sql = "INSERT INTO ratelimit (who,lastclaim) VALUES ('".addslashes($_SESSION['username'])."',".time().");";
                        //echo $sql;
                        $result = $conn->query($sql);
                    }
                }
                header("Location: /k/".$path[1] );
                die("Redirect");
            }
          }
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
        <a class="nav-link" href="/">Home <span class="sr-only">(current)</span></a>
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
if ( $path[0] == "newkey" ) {
    if ($loggedin == true) {
?>
<form action="/newkey/" method="POST">
  <fieldset>
    <div class="form-group">
      <label for="InputGameTitle" class="form-label mt-4">Game Title</label>
      <input type="GameTitle" class="form-control" id="InputGameTitle" name="InputGameTitle" placeholder="Game Title">
    </div>

    <div class="form-group">
      <label for="InputGameKey" class="form-label mt-4">Game Key, separate multiple keys with a space</label>
      <input type="GameKey" class="form-control" id="InputGameKey" name="InputGameKey" placeholder="ABCDE-12345-ABCDE">
    </div>

    <div class="form-group">
      <label for="InputKarmaLink" class="form-label mt-4">Minimum Link Karma (0 is disabled)</label>
      <input type="KarmaLink" class="form-control" id="InputKarmaLink" name="InputKarmaLink" placeholder="0" <?php if (isset($_SESSION['last_linkkarma'])) { echo "value='".$_SESSION['last_linkkarma']."'"; } ?>>
    </div>

    <div class="form-group">
      <label for="InputKarmaComment" class="form-label mt-4">Minimum Comment Karma (0 is disabled)</label>
      <input type="KarmaComment" class="form-control" id="InputKarmaComment" name="InputKarmaComment" placeholder="0"<?php if (isset($_SESSION['last_commentkarma'])) { echo "value='".$_SESSION['last_commentkarma']."'"; } ?>>
    </div>

    <div class="form-group">
      <label for="InputAccountAge" class="form-label mt-4">Minimum Account Age is Days (0 is disabled)</label>
      <input type="AccountAge" class="form-control" id="InputAccountAge" name="InputAccountAge" placeholder="0" <?php if (isset($_SESSION['last_age'])) { echo "value='".$_SESSION['last_age']."'"; } ?>>
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
But unfortunately there is a limit of 1 key per 5 minutes<br>
To give everyone a fair chance to get keys.
<?php
}

if ( $path[0] == "" ) {
    echo $Parsedown->text(file_get_contents('about.md')) ;
}
if ( $path[0] == "profile" ) {
    if ($loggedin == true) {
      ?>

    <a href="/profile">Unclaimed Keys</a> | <a href="/profile/claimed">Claimed Keys</a>
    <table class="table table-hover">
    <thead>
      <tr>
        <th scope="col">Title</th>
        <th scope="col">Key</th>
        <th scope="col">Karma/Comment/Age</th>
        <th scope="col">Claimed</th>
        <th scope="col">Link</th>
        <th scope="col">Delete</th>
      </tr>
    </thead>
    <tbody>
<?php
        //default unclaimed
        $sql = "SELECT * FROM gamekeys WHERE reddit_owner = '".$_SESSION['username']."' AND claimed = 0;";
        if ( isset($path[1]) and $path[1] == "claimed") {
            $sql = "SELECT * FROM gamekeys WHERE reddit_owner = '".$_SESSION['username']."' AND claimed = 1;";
        }
$result = $conn->query($sql);

while ( $row = $result->fetch_assoc() ) {
?>
    <tr class="table-active">
        <th scope="row"><?php echo $row['gametitle']; ?></th>
        <td><?php echo $row['gamekey']; ?></td>
        <td><?php echo $row['karma_link']."/".$row['karma_comment']."/".$row['account_age']; ?></td>
        <td><a href="https://reddit.com/u/<?php echo $row['reddit_who']; ?>" target="_blank"><?php echo $row['reddit_who']; ?></a></td>
<?php /*
        <td><input value="https://keyshare.link/k/<?php echo $row['hash']; ?>" class="form-control form-control-sm"></td>
*/ ?>
        <td><a href="https://keyshare.link/k/<?php echo $row['hash']; ?>">https://keyshare.link/k/<?php echo $row['hash']; ?></a></td>
        <td><?php
        if ( !isset($path[1]) or $path[1] != "claimed") {
            ?>
            <form action="/profile/delete/<?php echo $row['hash']; ?>" method="POST">
            <input type="submit" name="delete" value="delete">
            </form>
            <?php
        } else { echo "&nbsp;"; }
        ?></td>
    </tr>
<?php
    }
?>
    </tbody>
</table>
<a href="/newkey">Create New Giveaway</a>
<?php
    }



} else {

    if ( $path[0] == "k" ) {
        if (!preg_match('/[^A-Za-z0-9]/', $path[1])) // '/[^a-z\d]/i' should also work.
        {
            $stmt = $conn->prepare("SELECT * FROM gamekeys WHERE hash = ?;");
            $stmt->bind_param("s", $path[1]);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            if ( $row['hash'] == $path[1]) {
                if (isset($_SESSION['username']) and (($row['reddit_who'] == $_SESSION['username'] and $row['claimed'] == 1) or ( $loggedin AND (time() > ($row['dateclaimed'] + 1800 ) AND $row['dateclaimed'] > 1641324520 )))) {
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
                        }
                    }
                    echo "<br><br>This key was kindly gifted by <a href='https://reddit.com/u/".$row['reddit_owner']."' target='_blank'>u/".$row['reddit_owner']."</a>";
                    echo "<br><br>please remember to thank the user who shared this key by replying to their comment.";
                } elseif ($row['claimed'] == 1) {
                    echo "key already claimed by another user";
                } else {
                    if ($row['reddit'] == 1 and $loggedin == false) {
                        echo "this key requires you to be logged in to claim it";
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
