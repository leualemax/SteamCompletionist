<?PHP
/**
 * Handles all AJAX requests.
 * Mostly just the same as index.php
 *
 * @author Felix Kastner <felix@chapterfain.com>
 */
if (substr($_SERVER['HTTP_HOST'], 0, 4) !== 'www.') {
    header('Location: http://www.' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
}

// Set up the session ( SGS = Steam Game Session )
session_name('SGS');
session_start();

// We need to set the time out to make sure out steam API requests go through
ini_set('default_socket_timeout', 30);

require 'Classes/Common/AutoLoader/AutoLoader.php';
require '../private/Config.php';

$autoLoader = new Classes\Common\AutoLoader\AutoLoader();

use Classes\Common\DI\Pimple as Pimple;
use Classes\Common\Database\PdoDb as PdoDb;
use Classes\Common\User\User as User;
use Classes\Common\OpenID\LightOpenID as LightOpenID;
use Classes\Common\Logger\DbLogger as DbLogger;
use Classes\Common\Util\Util as Util;
use Classes\SteamCompletionist\Steam\SteamUser as SteamUser;
use Classes\SteamCompletionist\Steam\SteamUserSearch as SteamUserSearch;

try {
    $c = new Pimple();

    $c['config'] = $c->share(function () {
        return new Config();
    });

    $c['db'] = $c->share(function ($c) {
        return new PdoDb($c['config']->db);
    });

    $c['logger'] = $c->share(function ($c) {
        return new DbLogger($c['config']->logger, $c['db']);
    });

    $c['user'] = $c->share(function ($c) {
        return new User($c['config']->session, $_SERVER, $_SESSION, $_COOKIE, $c['db'], $c['logger']);
    });

    $c['util'] = $c->share(function ($c) {
        return new Util($c['logger']);
    });

    /** @var \Classes\Common\Database\DatabaseInterface $db */
    $db = $c['db'];

    /** @var User $user */
    $user = $c['user'];

    /** @var \Classes\Common\Logger\LoggerInterface $logger */
    $logger = $c['logger'];

    /** @var Util $util */
    $util = $c['util'];

    $_SESSION = $user->session;
    $_COOKIE = $user->cookie;

    $logger->setUser($user->userId);
    $logger->setIP($_SERVER['REMOTE_ADDR']);

    $c['steamUser'] = $c->share(function ($c) {
        return new SteamUser($c['config']->steam, $_GET['user'], $c['db'], $c['util'], $c['logger'], $_GET['user'] === $c['user']->userId);
    });

    /** @var SteamUser $steamUser */
    $steamUser = $c['steamUser'];


    $return = array();

    switch ($_GET['mode']) {
        case 'getsteamdata':
            $steamUser->loadLocalUserInfo();
            $steamUser->loadRemoteUserInfo();
            $return['steamuser'] = $steamUser->getUserData();

            // Only load game info for the account's owner
            if($steamUser->isOwner) {
                $steamUser->loadLocalGames();
                $steamUser->loadRemoteGames();
                $return['steamgames'] = $steamUser->getGameData();
                $return['deletelist'] = $steamUser->getDeleteList();
            }
            break;

        case 'savegamestatus':
            // Sanity Checks:
            if (!$steamUser->isOwner) {
                throw new Exception('You are not the owner of this account.');
            } else if (!isset($_GET['value']) || !is_numeric($_GET['value']) || $_GET['value'] > 2 || $_GET['value'] < 0) {
                throw new Exception('Invalid game slot.');
            }

            $status = $_GET['value'];

            if (!$_GET['gid'] || !is_numeric($_GET['gid'])) {
                die(json_encode(array("errorlog" => "Invalid game id. ")));
            }
            $gameId = $_GET['gid'];

            $steamUser->loadLocalUserInfo();

            if ($steamUser->loadLocalGame($gameId)) {
                $steamUser->setStatus($gameId, $status);
            }
            $return['steamuser'] = $steamUser->getUserData();
            break;

        case 'swapgameslot':
            if (!$steamUser->isOwner) {
                throw new Exception('You are not the owner of this account.');
            } else if (!isset($_GET['value']) || !is_numeric($_GET['value']) || $_GET['value'] > 10 || $_GET['value'] < 0) {
                throw new Exception('Invalid target slot.');
            } else if (!isset($_GET['gid']) || !is_numeric($_GET['gid']) || $_GET['gid'] > 10 || $_GET['gid'] < 0) {
                throw new Exception('Invalid source slot.');
            }
            $sourceSlot = $_GET['gid'];
            $targetSlot = $_GET['value'];

            $sourceGame = 0;
            $targetGame = 0;

            $steamUser->loadLocalUserInfo();
            $steamUser->loadLocalSlotGames();

            // First we EMPTY both slots, so that points are given out properly
            if(isset($steamUser->slots[$sourceSlot])) {
                $sourceGame = $steamUser->slots[$sourceSlot];
                $steamUser->setSlot($sourceGame, 0);
            }
            if(isset($steamUser->slots[$targetSlot])) {
                $targetGame = $steamUser->slots[$targetSlot];
                $steamUser->setSlot($targetGame, 0);
            }

            // And then we swap the slots
            if($sourceGame) {
                $steamUser->setSlot($sourceGame, $targetSlot);
            }
            if($targetGame) {
                $steamUser->setSlot($targetGame, $sourceSlot);
            }
            break;

        case 'savegameslot':
            // Sanity Checks:
            if (!$steamUser->isOwner) {
                throw new Exception('You are not the owner of this account.');
            } else if (!isset($_GET['value']) || !is_numeric($_GET['value']) || $_GET['value'] > 10 || $_GET['value'] < 0) {
                throw new Exception('Invalid game slot.');
            }
            $slot = $_GET['value'];

            if (!$_GET['gid'] || !is_numeric($_GET['gid'])) {
                throw new Exception('Invalid game id.');
            }
            $gameId = $_GET['gid'];

            $steamUser->loadLocalUserInfo();

            if ($steamUser->loadLocalGame($gameId)) {
                $steamUser->setSlot($gameId, $slot);
            }
            $return['steamuser'] = $steamUser->getUserData();
            break;

        case 'achievpercent':
            // Sanity Checks:
            if (!$_GET['gid'] || !is_numeric($_GET['gid'])) {
                throw new Exception('Invalid game id.');
            }
            $gameId = $_GET['gid'];

            $steamUser->loadLocalGame($gameId);

            if (isset($steamUser->games[$gameId])) {
                /** @var \Classes\SteamCompletionist\Steam\SteamGame $game */
                $game = $steamUser->games[$gameId];
                if ($game->community) {
                    $game->getAchievementPercentage($steamUser->steamId);
                    $return['gameachiev'] = array('gameid' => $gameId, 'percentage' => $game->achievementPercentage);
                }
            }
            break;

        case 'savenumtobeat':
            // Sanity Checks:
            if (!$steamUser->isOwner) {
                throw new Exception('You are not the owner of this account.');
            } else if (!isset($_GET['value']) || !is_numeric($_GET['value']) || $_GET['value'] > 10 || $_GET['value'] < 1) {
                throw new Exception('Invalid number for to beat slots.');
            }
            $newToBeatNum = $_GET['value'];

            $steamUser->loadLocalUserInfo();
            $steamUser->loadLocalGames();
            $steamUser->setNumToBeat($newToBeatNum);

            break;

        case 'saveconsiderbeaten':
            // Sanity Checks:
            if (!$steamUser->isOwner) {
                throw new Exception('You are not the owner of this account.');
            } else if (!isset($_GET['value']) || !is_numeric($_GET['value']) || $_GET['value'] > 1 || $_GET['value'] < 0) {
                throw new Exception('Invalid number for consider beaten toggle.');
            }
            $newConsiderBeaten = $_GET['value'];

            $steamUser->loadLocalUserInfo();
            $steamUser->setConsiderBeaten($newConsiderBeaten);

            break;

        case 'savehidequickstats':
            // Sanity Checks:
            if (!$steamUser->isOwner) {
                throw new Exception('You are not the owner of this account.');
            } else if (!isset($_GET['value']) || !is_numeric($_GET['value']) || $_GET['value'] > 1 || $_GET['value'] < 0) {
                throw new Exception('Invalid number for hide quick stats toggle.');
            }
            $newHideQuickStats = $_GET['value'];

            $steamUser->loadLocalUserInfo();
            $steamUser->setHideQuickStats($newHideQuickStats);

            break;

        case 'savehideaccountstats':
            // Sanity Checks:
            if (!$steamUser->isOwner) {
                throw new Exception('You are not the owner of this account.');
            } else if (!isset($_GET['value']) || !is_numeric($_GET['value']) || $_GET['value'] > 1 || $_GET['value'] < 0) {
                throw new Exception('Invalid number for hide account stats toggle.');
            }
            $newHideAccountStats = $_GET['value'];

            $steamUser->loadLocalUserInfo();
            $steamUser->setHideAccountStats($newHideAccountStats);

            break;

        case 'savehidesocial':
            // Sanity Checks:
            if (!$steamUser->isOwner) {
                throw new Exception('You are not the owner of this account.');
            } else if (!isset($_GET['value']) || !is_numeric($_GET['value']) || $_GET['value'] > 1 || $_GET['value'] < 0) {
                throw new Exception('Invalid number for hide social toggle.');
            }
            $newHideSocial = $_GET['value'];

            $steamUser->loadLocalUserInfo();
            $steamUser->setHideSocial($newHideSocial);

            break;

        case 'saveprivate':
            // Sanity Checks:
            if (!$steamUser->isOwner) {
                throw new Exception('You are not the owner of this account.');
            } else if (!isset($_GET['value']) || !is_numeric($_GET['value']) || $_GET['value'] > 1 || $_GET['value'] < 0) {
                throw new Exception('Invalid number for hide social toggle.');
            }
            $newPrivate = $_GET['value'];

            $steamUser->loadLocalUserInfo();
            $steamUser->setPrivate($newPrivate);

            break;

        case 'stats':
                $categoryID = array(
                    1 => 'owned',
                    2 => 'beaten',
                    3 => 'blacklisted',
                    4 => 'leastplayed',
                    5 => 'unbeaten',
                    6 => 'leastowned',
                    7 => 'mosttime',
                    8 => 'most2weeks',
                );

                $categoryLabel = array(
                    1 => 'Owned by',
                    2 => 'Beaten by',
                    3 => 'Blacklisted by',
                    4 => 'Utterly neglected by',
                    5 => 'Game is not impressed by',
                    6 => 'Owned by',
                    7 => 'Days devoured',
                    8 => 'Recent days devoured',
                );

                $db->prepare('SELECT `steamGameDB`.`name`, `statsDB`.`category`, `statsDB`.`value` FROM `statsDB`, `steamGameDB` WHERE `statsDB`.`appid` = `steamGameDB`.`appid` ORDER BY `statsDB`.`category` ASC, `statsDB`.`id` ASC');
                $db->execute();

                $lastCategory = 0;
                $count = 0;

                $result = $db->fetch();
                if ($result) {
                    // Stats
                    do {
                        if( isset($categoryID[$result['category']]) && isset($categoryLabel[$result['category']])) {
                            if($result['category'] !== $lastCategory) {
                                $return['stats'][$result['category']]['cols'] = array(
                                    array('id' => 'game', 'label' => 'Game Name', 'type' => 'string'),
                                    array('id' => $categoryID[$result['category']], 'label' => $categoryLabel[$result['category']], 'type' => 'number')
                                );
                                $lastCategory = $result['category'];
                            }

                            $temp = array();
                            $temp[] = array('v' => (string)$result['name']);
                            $temp[] = array('v' => (int)$result['value']);

                            $return['stats'][$result['category']]['rows'][] = array('c' => $temp);
                        }
                    } while ($result = $db->fetch());
                    $logger->addEntry('Grabbed stats from database.');
                } else {
                    // No Stats found
                    throw new Exception('Grabbing stats failed.');
                }
            break;

        case 'search':
            // Sanity checks
            if (!isset($_GET['search']) || empty($_GET['search'])) {
                throw new Exception('Empty or non-existant search string');
            }

            $searchString = $_GET['search'];

            $c['steamUserSearch'] = $c->share(function ($c) {
                    return new SteamUserSearch($c['config']->steam, $c['db'], $c['util'], $c['logger']);
                });
            /** @var SteamUserSearch $steamUserSearch */
            $steamUserSearch = $c['steamUserSearch'];

            if($result = $steamUserSearch->search($searchString)) {
                $return['searchresult'] = $result;
            } else {
                throw new Exception('No results for ' . htmlentities($searchString) . '.<br>Valid search terms are:<br>
                    <span class="bold">Steam 64 ID</span> (Example: 76561197992057625)<br>
                    <span class="bold">Custom ID</span> (Can be set in your profile settings)<br>
                    <span class="bold">Persona Name</span> (Only works if the person you are searching for has visited this site)<br>');
            }
            break;

        default:
            throw new Exception('No mode specified.');
            break;
    }


    $logger->addEntry('Ajax request finished');
    echo json_encode($return);
} catch (Exception $e) {
    echo json_encode(array('errorlog' => $e->getMessage()));
}