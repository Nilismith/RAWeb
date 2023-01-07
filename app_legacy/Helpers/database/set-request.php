<?php

use LegacyApp\Community\Enums\ClaimStatus;
use LegacyApp\Platform\Enums\AchievementType;

/**
 * Gets a list of set requests made by a given user.
 */
function getUserRequestList(string $user = null): array
{
    sanitize_sql_inputs($user);

    $retVal = [];

    $query = "
        SELECT
            sr.GameID as GameID,
            gd.Title as GameTitle,
            gd.ImageIcon as GameIcon,
            c.name as ConsoleName,
            GROUP_CONCAT(DISTINCT(IF(sc.Status = " . ClaimStatus::Active . ", sc.User, NULL))) AS Claims
        FROM
            SetRequest sr
        LEFT JOIN
            SetClaim sc ON (sr.GameID = sc.GameID)
        LEFT JOIN
            GameData gd ON (sr.GameID = gd.ID)
        LEFT JOIN
            Console c ON (gd.ConsoleID = c.ID)
        WHERE
            sr.user = '$user'
        GROUP BY
            sr.GameID
        ORDER BY
            GameTitle ASC";

    $dbResult = s_mysql_query($query);

    if ($dbResult !== false) {
        $gameIDs = [];
        while ($nextData = mysqli_fetch_assoc($dbResult)) {
            $gameIDs[] = $nextData['GameID'];
            $nextData['AchievementCount'] = 0;
            $retVal[] = $nextData;
        }

        if (!empty($gameIDs)) {
            $query = "SELECT GameID, COUNT(ID) AS AchievementCount FROM Achievements"
                   . " WHERE GameID IN (" . implode(',', $gameIDs) . ")"
                   . " AND Flags = " . AchievementType::OfficialCore
                   . " GROUP BY GameID";

            $dbResult = s_mysql_query($query);

            if ($dbResult !== false) {
                while ($nextData = mysqli_fetch_assoc($dbResult)) {
                    foreach ($retVal as &$game) {
                        if ($game['GameID'] == $nextData['GameID']) {
                            $game['AchievementCount'] = $nextData['AchievementCount'];
                            break;
                        }
                    }
                }
            } else {
                log_sql_fail();
            }
        }
    } else {
        log_sql_fail();
    }

    return $retVal;
}

/**
 * Gets the total and remaining set requests left for the given user.
 */
function getUserRequestsInformation(string $user, array $list, int $gameID = -1): array
{
    $requests = [];
    $requests['total'] = 0;
    $requests['used'] = 0;
    $requests['requestedThisGame'] = 0;
    $requests['pointsForNext'] = 0;
    $requests['maxSoftcoreReached'] = false;
    $points = 0;
    $maxSoftcoreThreshold = 10000; // Softcore points count towards requests up to 10000 points

    if (getPlayerPoints($user, $userPoints)) {
        $points += ($userPoints['RAPoints'] + min($userPoints['RASoftcorePoints'], $maxSoftcoreThreshold));
        $requests['maxSoftcoreReached'] = ($userPoints['RASoftcorePoints'] >= $maxSoftcoreThreshold);
    }

    // logic behind the amount of requests based on player's score:
    $boundariesAndChunks = [
        100000 => 10000, // from 100k to infinite, +1 for each 10k chunk of points
        10000 => 5000,  // from 10k to 100k, +1 for each 5k chunk
        2500 => 2500,    // from 2.5k to 10k, +1 for each 2.5k chunk
        0 => 1250,       // from 0 to 2.5k, +1 for each 1.25k chunk
    ];

    $pointsLeft = $points;
    foreach ($boundariesAndChunks as $boundary => $chunk) {
        if ($pointsLeft >= $boundary) {
            $aboveBoundary = $pointsLeft - $boundary;
            $requests['total'] += floor($aboveBoundary / $chunk);

            if ($requests['pointsForNext'] === 0) {
                $nextThreshold = $boundary + (floor($aboveBoundary / $chunk) + 1) * $chunk;
                $requests['pointsForNext'] = $nextThreshold - $pointsLeft;
            }

            $pointsLeft = $boundary;
        }
    }

    // adding the number of years the user is here
    $requests['total'] += getAccountAge($user);

    // Determine how many of the users current requests are still valid.
    // Requests made for games that since received achievements do not count towards a used request
    foreach ($list as $request) {
        // If the game does not have achievements then it counts as a legit request
        if ($request['AchievementCount'] == 0) {
            $requests['used']++;
        }

        // Determine if we have made a request for the input game
        if ($request['GameID'] == $gameID) {
            $requests['requestedThisGame'] = 1;
        }
    }

    $requests['remaining'] = $requests['total'] - $requests['used'];

    return $requests;
}

/**
 * Toggles a user set request.
 * If the user has not requested the set then add an entry to the database.
 * If the user has requested the set then remove it from the database.
 */
function toggleSetRequest(string $user, int $gameID, int $remaining): bool
{
    sanitize_sql_inputs($user, $gameID);

    $query = "
        SELECT
            COUNT(*) FROM SetRequest
        WHERE
            User = '$user'
        AND
            GameID = '$gameID'";

    $dbResult = s_mysql_query($query);

    if ($dbResult !== false) {
        if (mysqli_fetch_assoc($dbResult)['COUNT(*)'] == 1) {
            $query2 = "
                DELETE
                    FROM SetRequest
                WHERE
                    (`User` = '$user')
                AND
                    (`GameID` = '$gameID')";

            if (s_mysql_query($query2)) {
                return true;
            } else {
                return false;
            }
        } else {
            // Only insert a set request if the user has some available
            if ($remaining > 0) {
                $query2 = "
                    INSERT
                        INTO SetRequest (`User`, `GameID`)
                    VALUES ('$user', '$gameID')";
                if (s_mysql_query($query2)) {
                    return true;
                } else {
                    return false;
                }
            }
        }
    }

    return false;
}

/**
 * Gets the number of set requests for a given game.
 */
function getSetRequestCount(int $gameID): int
{
    sanitize_sql_inputs($gameID);
    settype($gameID, 'integer');
    if ($gameID < 1) {
        return 0;
    }

    $query = "SELECT COUNT(*) AS Request FROM
                SetRequest
                WHERE GameID = $gameID";

    $dbResult = s_mysql_query($query);

    if ($dbResult !== false) {
        return (int) (mysqli_fetch_assoc($dbResult)['Request'] ?? 0);
    } else {
        return 0;
    }
}

/**
 * Gets a list of set requestors for a given game.
 */
function getSetRequestorsList(int $gameID, bool $getEmailInfo = false): array
{
    sanitize_sql_inputs($gameID);
    settype($gameID, 'integer');

    $retVal = [];

    if ($gameID < 1) {
        return [];
    }

    if ($getEmailInfo) {
        $query = "
        SELECT
            sr.User AS Requestor,
            ua.EmailAddress AS Email,
            gd.Title AS Title
        FROM
            SetRequest AS sr
        LEFT JOIN
            UserAccounts ua on ua.User = sr.User
        LEFT JOIN
            GameData gd ON sr.GameID = gd.ID
        WHERE
        GameID = $gameID";
    } else {
        $query = "
            SELECT
            User AS Requestor
        FROM
            SetRequest
        WHERE
            GameID = $gameID";
    }

    $dbResult = s_mysql_query($query);
    if ($dbResult !== false) {
        while ($nextData = mysqli_fetch_assoc($dbResult)) {
            $retVal[] = $nextData;
        }
    } else {
        log_sql_fail();
    }

    return $retVal;
}

/**
 * Gets a list of the most requested sets without core achievements.
 */
function getMostRequestedSetsList(array|int|null $console, int $offset, int $count): array
{
    sanitize_sql_inputs($offset, $count);

    $retVal = [];

    $query = "
        SELECT
            COUNT(DISTINCT(sr.User)) AS Requests,
            sr.GameID as GameID,
            gd.Title as GameTitle,
            gd.ImageIcon as GameIcon,
            c.name as ConsoleName,
            GROUP_CONCAT(DISTINCT(IF(sc.Status = " . ClaimStatus::Active . ", sc.User, NULL))) AS Claims
        FROM
            SetRequest sr
        LEFT JOIN
            SetClaim sc ON (sr.GameID = sc.GameID)
        LEFT JOIN
            GameData gd ON (sr.GameID = gd.ID)
        LEFT JOIN
            Console c ON (gd.ConsoleID = c.ID)
        WHERE
            sr.GameID NOT IN (SELECT DISTINCT(GameID) FROM Achievements where Flags = '3') ";

    if (is_array($console)) {
        $query .= ' AND c.ID IN (' . implode(',', $console) . ') ';
    } elseif (!empty($console)) {
        sanitize_sql_inputs($console);
        $query .= " AND c.ID = $console ";
    }

    $query .= "
            GROUP BY
                sr.GameID
            ORDER BY
                Requests DESC, gd.Title
            LIMIT
                $offset, $count";

    $dbResult = s_mysql_query($query);

    if ($dbResult !== false) {
        while ($nextData = mysqli_fetch_assoc($dbResult)) {
            $retVal[] = $nextData;
        }
    } else {
        log_sql_fail();
    }

    return $retVal;
}

/**
 * Gets the number of set-less games with at least one set request.
 */
function getGamesWithRequests(array|int|null $console): int
{
    $query = "
        SELECT
            COUNT(DISTINCT sr.GameID) AS Games,
            sr.GameID as GameID,
            c.name as ConsoleName
        FROM
            SetRequest sr
        LEFT JOIN
            GameData gd ON (sr.GameID = gd.ID)
        LEFT JOIN
            Console c ON (gd.ConsoleID = c.ID)
        WHERE
             GameID NOT IN (SELECT DISTINCT(GameID) FROM Achievements where Flags = '3') ";

    if (is_array($console)) {
        $query .= ' AND c.ID IN (' . implode(',', $console) . ') ';
    } elseif (!empty($console)) {
        sanitize_sql_inputs($console);
        $query .= " AND c.ID = $console ";
    }

    $dbResult = s_mysql_query($query);

    if (!$dbResult) {
        return 0;
    }

    return (int) mysqli_fetch_assoc($dbResult)['Games'];
}