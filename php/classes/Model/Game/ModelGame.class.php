<?php
namespace AttOn\Model\Game;
use AttOn\Model\DataBase\DataSource;
use AttOn\Exceptions;

class ModelGame {

    private static $_Logger;

    // currently (seleced) game model
    private static $current_game = null;

    // list of all game models
    private static $games = array();

    // pre filled member_vars
    private $id; // int
    private $name; // string
    private $id_game_mode; // int
    private $playerslots; // int
    private $id_creator; // int
    private $pw_protected; // bool
    private $status; // string
    private $id_phase; // int
    private $round; // int
    private $processing; // bool

    /**
     * creates new game object, fills in relevant info if id given, otherwise use create function to create new game
     *
     * @param int $id_game
     * @throws NullPointerException
     * @return void
     */
    private function __construct($id_game) {
        $id_game = intval($id_game);
        $this->id = intval($id_game);

        if (!$this->fill_member_vars()) throw new NullPointerException('Game not found.');
        if (!isset(self::$_Logger)) self::$_Logger = Logger::getLogger('ModelGame');
    }

    /**
     * returns game model for given id
     * @param int $id_game
     * @throws NullPointerException (if game not found)
     * @return ModelGame
     */
    public static function getGame($id_game) {
        if (isset(self::$games[$id_game])) return self::$games[$id_game];

        return self::$games[$id_game] = new ModelGame($id_game);
    }

    /**
     * returns an iterator for games
     * @param $status - define for game status
     * @param int $id_user
     * @return iterator
     */
    public static function iterator($status,$id_user = null) {
        $games = array();

        $dict = array();
        switch ($status) {
            case GAME_STATUS_ALL:
                $query = 'get_all_game_ids';
                break;
            case GAME_STATUS_DONE:
                $query = 'get_done_game_ids';
                break;
            case GAME_STATUS_NEW:
                $query = 'get_new_game_ids';
                break;
            case GAME_STATUS_RUNNING:
                $query = 'get_running_game_ids';
                break;
            case GAME_STATUS_STARTED:
                $query = 'get_started_game_ids';
                break;
        }
        if ($id_user != null) {
            $query .= '_for_user';
            $dict[':id_user'] = intval($id_user);
        }
        $result = DataSource::Singleton()->epp($query,$dict);
        foreach ($result as $game) {
            $id_game = $game['id'];
            if (!isset(self::$games[$id_game])) {
                self::$games[$id_game] = new ModelGame($id_game);
            }
            $games[] = self::$games[$id_game];
        }

        return new ModelIterator($games);
    }

    /**
     *
     * tries to create a new game - returns true on success
     * @param string $name
     * @param int $game_mode
     * @param int $players
     * @param int $id_creator
     * @param string $password
     * @throws GameCreationException
     * @return ModelGame
     */
    public static function createGame($name, $game_mode, $players, $id_creator, $password) {
        $result = DataSource::Singleton()->epp('check_game_name',array(':name' => $name));
        if (!empty($result)) throw new GameCreationException('Spielname bereits vergeben!');
        $result = DataSource::Singleton()->epp('check_game_mode',array(':id_game_mode' => $game_mode));
        if (empty($result)) throw new GameCreationException('Ungültiger Spielmodus.');
        // :game_name, :id_game_mode, :players, :id_creator
        $dict = array();
        $dict[':game_name'] = $name;
        $dict[':id_game_mode'] = $game_mode;
        $dict[':players'] = $players;
        $dict[':id_creator'] = $id_creator;
        if (empty($password)) $query = 'create_game_without_pw';
        else {
            $query = 'create_game_with_pw';
            $dict[':password'] = $password;
        }

        try {
            DataSource::Singleton()->epp($query,$dict);
        } catch (DataSourceException $ex) {
            throw new GameCreationException('Unexpected error. Please try again.');
        }

        $result = DataSource::Singleton()->epp('check_game_name',array(':name' => $name));
        $id_game = intval($result[0]['id']);
        self::setGameSpecificQueries($id_game);

        DataSource::Singleton()->epp('create_areas_table',array());
        DataSource::Singleton()->epp('create_battle_reports_table',array());
        DataSource::Singleton()->epp('create_br_units_table',array());
        DataSource::Singleton()->epp('create_br_user_table',array());
        DataSource::Singleton()->epp('create_moves_table',array());
        DataSource::Singleton()->epp('create_moves_new_ships_table',array());
        DataSource::Singleton()->epp('create_moves_ships_table',array());
        DataSource::Singleton()->epp('create_moves_units_table',array());
        DataSource::Singleton()->epp('create_moves_steps_table',array());
        DataSource::Singleton()->epp('create_moves_steps_zareas_table',array());
        DataSource::Singleton()->epp('create_traderoutes_table',array());
        DataSource::Singleton()->epp('create_units_table',array());
        DataSource::Singleton()->epp('create_units_land_table',array());
        DataSource::Singleton()->epp('create_units_sea_table',array());
        DataSource::Singleton()->epp('create_units_in_harbor_table',array());
        return self::getGame($id_game);
    }

    /**
     *
     * deletes all tables,rows from database for this game
     * @throws GameAdministrationException - if game isn't loaded or the game isn't new
     * @return bool - true if successfull
     */
    public static function deleteGame($id_game) {
        try {
            $_Game = self::getGame($id_game);
        } catch (NullPointerException $ex) {
            throw new GameAdministrationException('Game not found.');
        }
        if ($_Game->getStatus() != GAME_STATUS_NEW) throw new GameAdministrationException('Only new games can be deleted.');

        self::setGameSpecificQueries($id_game);
        try {
            DataSource::Singleton()->epp('drop_areas_table',array());
            DataSource::Singleton()->epp('drop_battle_reports_table',array());
            DataSource::Singleton()->epp('drop_br_units_table',array());
            DataSource::Singleton()->epp('drop_br_user_table',array());
            DataSource::Singleton()->epp('drop_moves_table',array());
            DataSource::Singleton()->epp('drop_moves_new_ships_table',array());
            DataSource::Singleton()->epp('drop_moves_ships_table',array());
            DataSource::Singleton()->epp('drop_moves_units_table',array());
            DataSource::Singleton()->epp('drop_moves_steps_table',array());
            DataSource::Singleton()->epp('drop_moves_steps_zareas_table',array());
            DataSource::Singleton()->epp('drop_traderoutes_table',array());
            DataSource::Singleton()->epp('drop_units_table',array());
            DataSource::Singleton()->epp('drop_units_land_table',array());
            DataSource::Singleton()->epp('drop_units_sea_table',array());
            DataSource::Singleton()->epp('drop_units_in_harbor_table',array());
        } catch (DataSourceException $ex) {
            if (!isset(self::$_Logger)) self::$_Logger = Logger::getLogger('ModelGame');
            self::$_Logger->error($ex);
        }
        $dict = array(':id_game' => $id_game);
        DataSource::Singleton()->epp('delete_game',$dict);

        ModelIsInGameInfo::deleteIsInGameInfos($id_game);
        ModelInGamePhaseInfo::deleteInGamePhaseInfos($id_game);

        unset(self::$games[$id_game]);

        return true;
    }

    /**
     * returns all ids of all games where everybody is finished
     * @return array(int id_game)
     */
    public static function getGamesForProcessing() {
        $query = 'get_games_rdy_v2';
        $output = array();
        $result = DataSource::getInstance()->epp($query);
        foreach ($result as $line) {
            $output[] = $line['id'];
        }
        return $output;
    }

    /**
     * sets game status of a new game to GAME_STATUS_STARTED
     * @throws GameAdministrationException
     * @return bool
     */
    public function startGame() {
        if ($this->status != GAME_STATUS_NEW) throw new GameAdministrationException('Only new games can be started.');

        // allocate starting sets to users
        $iter_player = ModelIsInGameInfo::iterator(null,$this->id);
        $players = $iter_player->size();
        $iter_sets = ModelStartingSet::iterator($players,true);
        while ($iter_player->hasNext()) {
            if (!$iter_sets->hasNext()) throw new GameAdministrationException('Not enough starting sets found!');
            $iter_player->next()->setStartingSet($iter_sets->next()->getId());
        }

        // allocate resources
        $iter_poor = ModelEconomy::iterator(ECONOMY_POOR);
        $iter_weak = ModelEconomy::iterator(ECONOMY_WEAK);
        $iter_normal = ModelEconomy::iterator(ECONOMY_NORMAL);
        $iter_strong = ModelEconomy::iterator(ECONOMY_STRONG);
        $iter_areas = ModelArea::iterator(TYPE_LAND);
        while ($iter_areas->hasNext()) {
            $_Area = $iter_areas->next();
            switch ($_Area->getEconomy()) {
                case ECONOMY_POOR:
                    $_Eco = $iter_poor->next();
                    break;
                case ECONOMY_WEAK:
                    $_Eco = $iter_weak->next();
                    break;
                case ECONOMY_NORMAL:
                    $_Eco = $iter_normal->next();
                    break;
                case ECONOMY_STRONG:
                    $_Eco = $iter_strong->next();
                    break;
            }
            ModelGameArea::setGameArea($this->id, 0, NEUTRAL_COUNTRY, $_Area->getId(), $_Eco->getIdResource(), $_Eco->getResPower());
        }

        // create sea areas
        $iter_sea = ModelArea::iterator(TYPE_SEA);
        while ($iter_sea->hasnext()) {
            $_Area = $iter_sea->next();
            ModelGameArea::setGameArea($this->id, 0, NEUTRAL_COUNTRY, $_Area->getId(), RESOURCE_NONE, 0);
        }

        // set game to started
        $this->setStatus(GAME_STATUS_STARTED);

        return true;
    }

    /**
     * sets the game password, set to no password if null given
     * @param string $password
     * @return void
     */
    public function setPassword($password = null) {
        $query = '';
        $dict = array();
        $dict[':id_game'] = $this->id;
        if ($password == null) {
            $this->pw_protected = false;
            $query = 'delete_game_password';
        }
        else {
            $this->pw_protected = true;
            $query = 'update_game_password';
            $dict[':password'] = $password;
        }
        DataSource::Singleton()->epp($query,$dict);
    }

    /**
     * sets the game status (and if necessary also changes the phase)
     * @param enum $status
     * @return void
     */
    public function setStatus($status) {
        if ($this->status == $status) return;
        $query = 'set_game_status';
        $dict = array();
        $dict[':id_game'] = $this->id;
        $dict[':status'] = $status;
        try {
            DataSource::Singleton()->epp($query,$dict);
            $this->status = $status;
        } catch (DataSourceException $ex) {
            self::$_Logger->error($ex);
            return;
        }

        if ($this->status == GAME_STATUS_NEW) {
            $this->setPhase(PHASE_GAME_START);
        } else if ($this->status == GAME_STATUS_STARTED && $this->id_phase < PHASE_SELECTSTART) {
            $this->setPhase(PHASE_SELECTSTART);
        } else if ($this->status == GAME_STATUS_RUNNING && $this->id_phase >= GAME_STATUS_STARTED) {
            $this->setPhase(PHASE_LANDMOVE);
        }
    }

    /**
     * sets the game phase (and if necessary also changes the status)
     * @param int $id_phase
     * @throws NullPointerException
     * @return void
     */
    public function setPhase($id_phase) {
        $id_phase = intval($id_phase);
        if ($this->id_phase == $id_phase) return;
        ModelPhase::getPhase($id_phase);

        $query = 'set_game_phase';
        $dict = array();
        $dict[':id_game'] = $this->id;
        $dict[':id_phase'] = $id_phase;
        DataSource::Singleton()->epp($query,$dict);
        $this->id_phase = $id_phase;

        if ($this->status == GAME_STATUS_DONE) return;
        if ($this->id_phase < PHASE_GAME_START) {
            $this->setStatus(GAME_STATUS_RUNNING);
        } else if ($this->id_phase == PHASE_GAME_START) {
            $this->setStatus(GAME_STATUS_NEW);
        } else if ($this->id_phase > PHASE_GAME_START) {
            $this->setStatus(GAME_STATUS_STARTED);
        }
    }

    /**
     * moves the game in the next phase, updates phase and game_round and status if necessary
     * @return void
     */
    public function moveToNextPhase() {
        // get phases
        $_ModelGameMode = ModelGameMode::getGameMode($this->id_game_mode);
        $phases = $_ModelGameMode->getPhases();

        // check which phase is next
        $next_phase;
        $add_round = false;
        $pos = array_search($this->id_phase,$phases);

        if (!isset($phases[$pos+1])) {
            $next_phase = $phases[0];
            $add_round = true;
        } elseif ($this->status == GAME_STATUS_RUNNING && $phases[$pos+1] >= PHASE_GAME_START) {
            $next_phase = $phases[0];
            $add_round = true;
        } else {
            $next_phase = $phases[$pos+1];
        }

        // add round and set phase
        if ($add_round) $this->nextRound();
        $this->setPhase($next_phase);
    }

    /**
     * @return bool - true if game is processing
     */
    public function checkProcessing() {
        return $this->processing;
    }

    /**
     * @return bool - true if this game has a password set
     */
    public function checkPasswordProtection() {
        return $this->pw_protected;
    }

    /**
     * @param string $password
     * @return bool - true if password is correct
     */
    public function checkPassword($password) {
        $result = DataSource::Singleton()->epp('check_game_password',array(':id_game' => $this->id, ':password' => $password));
        if (empty($result)) return false;
        return true;
    }

    /**
     * @throws NullPointerException - if this color doesn't exist
     * @return boolean
     */
    public function checkIfColorIsFree($id_color) {
        $id_color = intval($id_color);
        ModelColor::getModelColor($id_color);
        $iter = ModelIsInGameInfo::iterator(null,$this->id);
        while ($iter->hasNext()) {
            if ($iter->next()->getIdColor() == $id_color) return false;
        }
        return true;
    }

    /**
     * @return dict(int id => dict(id = int, color = string))
     */
    public function getFreeColors() {
        $colors_taken = array();
        $output = array();
        // array with taken colors
        $iter = ModelIsInGameInfo::iterator(null,$this->id);
        while ($iter->hasNext()) {
            $colors_taken[] = $iter->next()->getIdColor();
        }

        $iter = ModelColor::iterator();
        while ($iter->hasNext()) {
            $_Color = $iter->next();
            if (in_array($_Color->getId(),$colors_taken)) continue;
            $output[$_Color->getId()] = array('id' => $_Color->getId(), 'color' => $_Color->getName());
        }
        return $output;
    }

    /**
     * @return int
     */
    public function getId() {
        return $this->id;
    }

    /**
     * @return string/enum (new, started, running, done)
     */
    public function getStatus() {
        return $this->status;
    }

    /**
     * @return array(int)
     */
    public function getPhases() {
        return $this->phases;
    }

    /**
     * @return int
     */
    public function getPlayerSlots() {
        return $this->playerslots;
    }

    /**
     * @return string
     */
    public function getName() {
        return $this->name;
    }

    /**
     * @return int
     */
    public function getFreeSlots() {
        return ($this->playerslots - $this->getNumberOfPlayers());
    }

    /**
     * @return ModelUser
     */
    public function getCreator() {
        return ModelUser::getUser($this->id_creator);
    }

    /**
     * @return int
     */
    public function getIdGameMode() {
        return $this->id_game_mode;
    }

    /**
     * @return int
     */
    public function getNumberOfPlayers() {
        $iter = ModelIsInGameInfo::iterator(null,$this->id);
        return $iter->size();
    }

    /**
     * @return int
     */
    public function getIdPhase() {
        return $this->id_phase;
    }

    /**
     * @return int
     */
    public function getRound() {
        return $this->round;
    }

    private static function setGameSpecificQueries($id_game) {
        SQLCommands::init($id_game);
    }

    private function fill_member_vars() {
        // check if there is a game
        $result = DataSource::Singleton()->epp('get_full_game_info',array(':id_game' => $this->id));
        if (empty($result)) return false;

        // fill in info
        $data = $result[0];
        $this->name = $data['name'];
        $this->id_game_mode = intval($data['id_game_mode']);
        $this->playerslots = intval($data['players']);
        $this->id_creator = intval($data['id_creator']);
        if ($data['password'] == null) $this->pw_protected = false;
        else $this->pw_protected = true;
        $this->status = $data['status'];
        $this->id_phase = intval($data['id_phase']);
        $this->round = intval($data['round']);
        if ($data['processing'] == 1) $this->processing = true;
        else $this->processing = false;

        return true;
    }

    private function nextRound() {
        $query = 'set_game_round';
        $dict = array();
        $dict[':id_game'] = $this->id;
        $dict[':round'] = $this->round+1;
        DataSource::getInstance()->epp($query,$dict);
        $this->round++;

    }

}
?>