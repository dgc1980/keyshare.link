<?php
session_start();
include('database.inc.php'); //database connection
require_once('config.inc.php'); //reddit and hcaptcha config
function geturl($url,$token) {
    $UA = "reddit-oauth/1.1.1 by dgc1980";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_VERBOSE, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, false);
    if ( $fields == null ) {
    } else {
            $fields_string = http_build_query($fields);
            curl_setopt($ch ,CURLOPT_POST, count($fields));
            curl_setopt($ch ,CURLOPT_POSTFIELDS, $fields_string);
    }
echo "".$token;
$headers = array(
'Authorization: bearer ' + $token,
);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION,1);
    curl_setopt($ch, CURLOPT_USERAGENT,$UA);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;

}
function getToken($length){
    global $conn;
    $token = "";
    //$codeAlphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
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

        $tt = $data['total'];

    }
    return $token;
}
$state = uniqid();



if ( isset($_GET['path']) ) {
    $path = explode('/',strtolower($_GET['path']));

    if ( $path[0] == "logout" ) {
        $_SESSION['loggedin'] = false;
        header("Location: https://keyshare.link/");
        die("Redirect");
    }
    if ( $path[0] == "login" ) {
        $code = $_GET['code'];
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
            $_SESSION['state'] = $state;
            $authUrl = $client->getAuthenticationUrl($authorizeUrl, $redirectUrl, array("scope" => "identity", "state" => md5(uniqid)));
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
                file_put_contents('a.txt', print_r($response,1));
            } else {
            }
        }
        header("Location: ".$_SESSION['redirect_uri']);
        $_SESSION['redirect_uri'] = "";
        die("Redirect");
    } else {
    }

} else {
   $path[0] = "";
}
unset($_SESSION['redirect_uri']);

$loggedin = $_SESSION['loggedin'];
if ( $loggedin != true ) { $loginurl = "/login"; }


if ( $path[0] == "newkey" ) {
    if ($loggedin == true) {
        if ($_POST['submit'] == 1) {
            $t = getToken(8);
            $sql = "INSERT INTO gamekeys (gametitle,gamekey,dateadded,captcha,reddit,reddit_owner,karma_link,karma_comment,account_age,hash) VALUES ('".addslashes($_POST['InputGameTitle'])."','".addslashes($_POST['InputGameKey'])."','".time()."',1,1,'".addslashes($_SESSION['username'])."',".intval($_POST['InputKarmaLink']).",".intval($_POST['InputKarmaComment']).",".intval($_POST['InputAccountAge']).",'".$t."');";
            $result = $conn->query($sql);
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



          if ( $s == 1) {
            if (!preg_match('/[^A-Za-z0-9]/', $path[1])) // '/[^a-z\d]/i' should also work.
            {

                $sql = "SELECT * FROM gamekeys WHERE hash = '".$path[1]."'";
                $result = $conn->query($sql);
                $row = $result->fetch_assoc();

                if ($row['claimed'] == 0) {
                    $sql = "UPDATE gamekeys SET claimed = 1 WHERE hash = '".$path[1]."';";
                    $result = $conn->query($sql);
                    $sql = "UPDATE gamekeys SET reddit_who = '".$_SESSION['username']."' WHERE hash = '".$path[1]."';";
                    $result = $conn->query($sql);
                    $sql = "UPDATE gamekeys SET dateclaimed = ".time()." WHERE hash = '".$path[1]."';";
                    $result = $conn->query($sql);
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
    <script src='https://www.hCaptcha.com/1/api.js' async defer></script>
</head>

<body style="text-align: center">


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
<?php if ( $_SESSION['loggedin'] == true ) { ?>
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
<br><br><br>
<div class="container">

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
      <label for="InputGameKey" class="form-label mt-4">Game Key</label>
      <input type="GameKey" class="form-control" id="InputGameKey" name="InputGameKey" placeholder="ABCDE-12345-ABCDE">
    </div>

    <div class="form-group">
      <label for="InputKarmaLink" class="form-label mt-4">Minimum Link Karma (0 is disabled)</label>
      <input type="KarmaLink" class="form-control" id="InputKarmaLink" name="InputKarmaLink" placeholder="0">
    </div>

    <div class="form-group">
      <label for="InputKarmaComment" class="form-label mt-4">Minimum Comment Karma (0 is disabled)</label>
      <input type="KarmaComment" class="form-control" id="InputKarmaComment" name="InputKarmaComment" placeholder="0">
    </div>

    <div class="form-group">
      <label for="InputAccountAge" class="form-label mt-4">Minimum Account Age is Days (0 is disabled)</label>
      <input type="AccountAge" class="form-control" id="InputAccountAge" name="InputAccountAge" placeholder="0">
    </div>

    <button type="submit" class="btn btn-primary" name="submit" value="1">Submit</button>
  </fieldset>
</form>
<?php
    //print_r($_POST);
    }
}



if ( $path[0] == "" ) {
?>
Thank you for visiting KeyShare<br><br>
I have created this site to help users share their game keys without worry of bots collecting them<br><br>
Giveaways have the option to apply restrictions like karma and account age<br><br>
These are FIRST COME FIRST SERVED giveaway, with no random giveaway<br><br>
The person who has created the giveaway is able to see the reddit account of who claimed it.<br><br>
You are more than welcome to use this on other subs and not limited to r/GameDeals<br><br>
<br><br><br><br><br><br>
<small><a href='https://github.com/dgc1980/keyshare.link'>source code</a></small><br><br>
<?php
}
if ( $path[0] == "profile" ) {
    if ($loggedin == true) {
        ?>
    <table class="table table-hover">
    <thead>
      <tr>
        <th scope="col">Title</th>
        <th scope="col">Key</th>
        <th scope="col">Karma/Comment/Age</th>
        <th scope="col">Claimed</th>
        <th scope="col">Link</th>
      </tr>
    </thead>
    <tbody>
<?php
$sql = "SELECT * FROM gamekeys WHERE reddit_owner = '".$_SESSION['username']."'";
$result = $conn->query($sql);

while ( $row = $result->fetch_assoc() ) {
?>
    <tr class="table-active">
        <th scope="row"><?php echo $row['gametitle']; ?></th>
        <td><?php echo $row['gamekey']; ?></td>
        <td><?php echo $row['karma_link']."/".$row['karma_comment']."/".$row['account_age']; ?></td>
        <td><a href="https://reddit.com/u/<?php echo $row['reddit_who']; ?>" target="_blank"><?php echo $row['reddit_who']; ?></a></td>
        <td><input value="https://keyshare.link/k/<?php echo $row['hash']; ?>" class="form-control form-control-sm"></td>
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
            $sql = "SELECT * FROM gamekeys WHERE hash = '".$path[1]."'";
            $result = $conn->query($sql);
            $row = $result->fetch_assoc();
            if ( $row['hash'] == $path[1]) {
                if ($row['reddit_who'] == $_SESSION['username'] and $row['claimed'] == 1) {
                    echo "<center>Here is your gifted key for,<br><b>".$row['gametitle']."</b>";
                    echo '<input value="'.$row['gamekey'].'" class="form-control form-control-sm" style="width: 300px; text-align: center;">';
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


<div id="footer" style="color: black;">created by <a href="https://reddit.com/u/dgc1980" target="_blank">u/dgc1980</a> for the caring users of <a href="https://reddit.com/r/GameDeals" target="_blank">r/GameDeals</a> who love to share their game keys - <small><a href='https://github.com/dgc1980/keyshare.link'>source code</a></small><br>
this is a free service, if you wish to donote, please do via <a href="https://ko-fi.com/dgc1980">Ko-fi</a> or BTC 33gAxQGTW84CmoskiuZdnMTtAqKaCQG8Pz
</div>

<script src="/cookie/load.js"></script>
</body>
<?php
if ($_GET['a'] == "1") {
    print_r($_SESSION);
}
?>
