<?php
/**
 * \Elabftw\Elabftw\Teams
 *
 * @author Nicolas CARPi <nicolas.carpi@curie.fr>
 * @copyright 2012 Nicolas CARPi
 * @see http://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */
namespace Elabftw\Elabftw;

use PDO;
use Exception;

/**
 * All about the teams
 */
class Teams extends Panel
{
    /** pdo object */
    protected $pdo;

    /**
     * Constructor
     *
     * @throws Exception if user is not admin
     */
    public function __construct()
    {
        $this->pdo = Db::getConnection();
    }

    /**
     * Add a new team
     *
     * @param string $name The new name of the team
     * @return bool The results of the SQL queries
     */
    public function create($name)
    {
        if (!$this->isSysAdmin()) {
            throw new Exception('Only admin can access this!');
        }
        $name = filter_var($name, FILTER_SANITIZE_STRING);

        // add to the teams table
        $sql = 'INSERT INTO teams (team_name, link_name, link_href) VALUES (:team_name, :link_name, :link_href)';
        $req = $this->pdo->prepare($sql);
        $req->bindParam(':team_name', $name);
        $req->bindValue(':link_name', 'Documentation');
        $req->bindValue(':link_href', 'doc/_build/html/');
        $result1 = $req->execute();
        // grab the team ID
        $new_team_id = $this->pdo->lastInsertId();

        // now we need to insert a new default set of status for the newly created team
        $sql = "INSERT INTO status (team, name, color, is_default) VALUES
        (:team, 'Running', '0096ff', 1),
        (:team, 'Success', '00ac00', 0),
        (:team, 'Need to be redone', 'c0c0c0', 0),
        (:team, 'Fail', 'ff0000', 0);";
        $req = $this->pdo->prepare($sql);
        $req->bindValue(':team', $new_team_id);
        $result2 = $req->execute();

        // insert only one item type with editme name
        $sql = "INSERT INTO `items_types` (`team`, `name`, `bgcolor`, `template`)
            VALUES (:team, 'Edit me', '32a100', '<p>Go to the admin panel to edit/add more items types!</p>');";
        $req = $this->pdo->prepare($sql);
        $req->bindValue(':team', $new_team_id);
        $result3 = $req->execute();

        // now we need to insert a new default experiment template for the newly created team
        $sql = "INSERT INTO `experiments_templates` (`team`, `body`, `name`, `userid`) VALUES
        (:team, '<p><span style=\"font-size: 14pt;\"><strong>Goal :</strong></span></p>
        <p>&nbsp;</p>
        <p><span style=\"font-size: 14pt;\"><strong>Procedure :</strong></span></p>
        <p>&nbsp;</p>
        <p><span style=\"font-size: 14pt;\"><strong>Results :</strong></span></p><p>&nbsp;</p>', 'default', 0);";
        $req = $this->pdo->prepare($sql);
        $req->bindValue(':team', $new_team_id);
        $result4 = $req->execute();

        return $result1 && $result2 && $result3 && $result4;
    }

    /**
     * Get all the teams
     *
     * @return array
     */
    public function read()
    {
        if (!$this->isSysAdmin()) {
            throw new Exception('Only admin can access this!');
        }
        $sql = "SELECT * FROM teams ORDER BY datetime DESC";
        $req = $this->pdo->prepare($sql);
        $req->execute();

        return $req->fetchAll();
    }

    /**
     * Update team
     *
     * @param array $params POST
     * @return bool
     */
    public function update($params)
    {
        $post_stamp = processTimestampPost();

        // CHECKS
        if ($params['deletable_xp'] == 1) {
            $deletable_xp = 1;
        } else {
            $deletable_xp = 0;
        }
        if (isset($params['link_name'])) {
            $link_name = filter_var($_POST['link_name'], FILTER_SANITIZE_STRING);
        } else {
            $link_name = 'Documentation';
        }
        if (isset($params['link_href'])) {
            $link_href = filter_var($params['link_href'], FILTER_SANITIZE_STRING);
        } else {
            $link_href = 'doc/_build/html/';
        }

        $sql = "UPDATE teams SET
            deletable_xp = :deletable_xp,
            link_name = :link_name,
            link_href = :link_href,
            stamplogin = :stamplogin,
            stamppass = :stamppass,
            stampprovider = :stampprovider,
            stampcert = :stampcert
            WHERE team_id = :team_id";
        $req = $this->pdo->prepare($sql);
        $req->bindParam(':deletable_xp', $deletable_xp);
        $req->bindParam(':link_name', $link_name);
        $req->bindParam(':link_href', $link_href);
        $req->bindParam(':stamplogin', $post_stamp['stamplogin']);
        $req->bindParam(':stamppass', $post_stamp['stamppass']);
        $req->bindParam(':stampprovider', $post_stamp['stampprovider']);
        $req->bindParam(':stampcert', $post_stamp['stampcert']);
        $req->bindParam(':team_id', $_SESSION['team_id']);

        return $req->execute();
    }

    /**
     * Edit the name of a team, called by ajax
     *
     * @param int $id The id of the team
     * @param string $name The new name we want
     * @return bool
     */
    public function updateName($id, $name)
    {
        if (!$this->isSysAdmin()) {
            throw new Exception('Only admin can access this!');
        }
        $name = filter_var($name, FILTER_SANITIZE_STRING);
        $sql = "UPDATE teams
            SET team_name = :name
            WHERE team_id = :id";
        $req = $this->pdo->prepare($sql);
        $req->bindParam(':name', $name);
        $req->bindParam(':id', $id, PDO::PARAM_INT);

        return $req->execute();
    }

    /**
     * Delete a team on if all the stats are at zero
     *
     * @param int $team ID of the team to delete
     * @return bool true if success, false if the team is not brand new
     */
    public function destroy($team)
    {
        if (!$this->isSysAdmin()) {
            throw new Exception('Only admin can access this!');
        }
        // check for stats, should be 0
        $count = $this->getStats($team);

        if ($count['totxp'] === '0' && $count['totdb'] === '0' && $count['totusers'] === '0') {

            $sql = "DELETE FROM teams WHERE team_id = :team_id";
            $req = $this->pdo->prepare($sql);
            $req->bindParam(':team_id', $team, PDO::PARAM_INT);
            $result1 = $req->execute();

            $sql = "DELETE FROM status WHERE team = :team_id";
            $req = $this->pdo->prepare($sql);
            $req->bindParam(':team_id', $team, PDO::PARAM_INT);
            $result2 = $req->execute();

            $sql = "DELETE FROM items_types WHERE team = :team_id";
            $req = $this->pdo->prepare($sql);
            $req->bindParam(':team_id', $team, PDO::PARAM_INT);
            $result3 = $req->execute();

            $sql = "DELETE FROM experiments_templates WHERE team = :team_id";
            $req = $this->pdo->prepare($sql);
            $req->bindParam(':team_id', $team, PDO::PARAM_INT);
            $result4 = $req->execute();

            return $result1 && $result2 && $result3 && $result4;
        }

        return false;
    }

    /**
     * Get statistics from a team or from the whole install
     *
     * @param int|null $team Id of the team, leave empty to get full stats
     * @return array
     */
    public function getStats($team = null)
    {
        if (!$this->isSysAdmin()) {
            throw new Exception('Only admin can access this!');
        }
        if (!is_null($team)) {
            $sql = "SELECT
            (SELECT COUNT(users.userid) FROM users WHERE users.team = :team) AS totusers,
            (SELECT COUNT(items.id) FROM items WHERE items.team = :team) AS totdb,
            (SELECT COUNT(experiments.id) FROM experiments WHERE experiments.team = :team) AS totxp";
            $req = $this->pdo->prepare($sql);
            $req->bindParam(':team', $team, \PDO::PARAM_INT);
        } else {
            $sql = "SELECT
            (SELECT COUNT(users.userid) FROM users) AS totusers,
            (SELECT COUNT(items.id) FROM items) AS totdb,
            (SELECT COUNT(teams.team_id) FROM teams) AS totteams,
            (SELECT COUNT(experiments.id) FROM experiments) AS totxp";
            $req = $this->pdo->prepare($sql);
        }
        $req->execute();

        return $req->fetch(\PDO::FETCH_NAMED);
    }

    /**
     * Toggle archived status for a team
     *
     * @param int $team the team id
     * @return bool
     */
    public function archive($team)
    {
        /*
          _______ ____  _____   ____
         |__   __/ __ \|  __ \ / __ \
            | | | |  | | |  | | |  | |
            | | | |  | | |  | | |  | |
            | | | |__| | |__| | |__| |
            |_|  \____/|_____/ \____/
         */
    }
}
