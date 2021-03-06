<?php
namespace Attack\Model\User;

use Attack\Model\Game\Start\ModelStartSet;
use Attack\Database\SQLConnector;
use Attack\Model\Game\ModelGame;
use Attack\Tools\Iterator\ModelIterator;
use Attack\Exceptions\DatabaseException;
use Attack\Exceptions\JoinUserException;
use Attack\Exceptions\GameAdministrationException;
use Attack\Exceptions\NullPointerException;

class ModelIsInGameInfo implements \JsonSerializable {

    // list of all models
    // $models = dict(int id_user = array(int id_game = ModelIsInGameInfo)))
    private static $models = array();

    // member vars
    private $id_user; // int
    private $id_game; // int
    private $id_color; // int id_color
    private $money; // int/numer
    private $id_set; // int id_set

    // resolved
    private $user; //ModelUser

    /**
     * returns the specific model
     *
     * @param $id_user int
     * @param $id_game int
     * @throws NullPointerException
     */
    private function __construct($id_user, $id_game) {
        $this->id_user = $id_user;
        $this->id_game = $id_game;

        $this->fill_member_vars();
    }

    /**
     * returns the specific model, if no id_game given, default rules are loaded (id_game == 0)
     *
     * @param $id_user int
     * @param $id_game int
     * @throws NullPointerException
     * @return ModelIsInGameInfo
     */
    public static function getIsInGameInfo($id_user, $id_game) {
        if (isset(self::$models[$id_user][$id_game])) {
            return self::$models[$id_user][$id_game];
        }

        return self::$models[$id_user][$id_game] = new ModelIsInGameInfo($id_user, $id_game);
    }

    /**
     * returns an iterator for all games the user is in or all users for given game
     *
     * @param int $id_user (if null, query is for all)
     * @param int $id_game (if null, query is for all)
     * @throws DatabaseException
     * @return ModelIterator
     */
    public static function iterator($id_user = null, $id_game = null) {
        $models = array();
        $dict = array();

        if ($id_user != null && $id_game != null) {
            $models = self::getIsInGameInfo(intval($id_user), intval($id_game));
            return new ModelIterator($models);
        } else if ($id_user == null) {
            $query = 'get_all_user_is_in_game_by_game';
            $dict[':id_game'] = intval($id_game);
        } else if ($id_game == null) {
            $query = 'get_all_user_is_in_game_by_user';
            $dict[':id_user'] = intval($id_user);
        } else {
            return new ModelIterator($models);
        }

        // query iigs
        $result = SQLConnector::Singleton()->epp($query, $dict);

        foreach ($result as $iig) {
            $id_game = $iig['id_game'];
            $id_user = $iig['id_user'];
            $models[] = self::getIsInGameInfo(intval($id_user), intval($id_game));
        }

        return new ModelIterator($models);
    }

    /**
     * joins the user in given game
     *
     * @param $id_user int
     * @param $id_game int
     * @param $id_color int
     * @throws NullPointerException
     * @throws JoinUserException
     * @return ModelIsInGameInfo
     */
    public static function joinGame($id_user, $id_game, $id_color = null) {
        $id_user = intval($id_user);
        $id_game = intval($id_game);
        if ($id_color !== null) {
            $id_color = intval($id_color);
        }

        // check if user and game exist
        $user = ModelUser::getUser($id_user);
        $game = ModelGame::getGame($id_game);

        if ($game->getFreeSlots() <= 0) {
            throw new JoinUserException('Game is full.');
        }

        // check if user is in the game
        $iter = ModelUser::iterator($id_game);
        while ($iter->hasNext()) {
            if ($iter->next() === $user) {
                throw new JoinUserException('User allready in this game.');
            }
        }

        // check if color is free
        if ($id_color !== null && !$game->checkIfColorIsFree($id_color)) {
            $id_color = null;
        }
        // get first free color
        if ($id_color === null) {
            $color_iter = ModelColor::iterator();
            while ($color_iter->hasNext()) {
                $color = $color_iter->next();
                if (!$game->checkIfColorIsFree($color->getId())) {
                    continue;
                }
                $id_color = $color->getId();
                break;
            }
        }
        if ($id_color === null) {
            throw new JoinUserException('No free color found.');
        }

        // insert player
        $dict = array();
        $dict[':id_user'] = $id_user;
        $dict[':id_game'] = $id_game;
        $dict[':id_color'] = $id_color;
        SQLConnector::Singleton()->epp('insert_user_in_game', $dict);

        // set user notification rules
        ModelInGamePhaseInfo::getInGamePhaseInfo($id_user, $id_game);

        return self::getIsInGameInfo($id_user, $id_game);
    }

    /**
     * deletes all models and corresponding database infos
     *
     * @param $id_game int
     * @param $id_user int (if null all models for this game are deleted)
     * @return void
     */
    public static function deleteIsInGameInfos($id_user = null, $id_game) {
        $query = ($id_user === null) ? 'delete_user_in_game_by_game' : 'delete_user_in_game';
        $dict = array(':id_game' => intval($id_game));
        if ($id_user !== null) {
            $dict[':id_user'] = intval($id_user);
        }
        SQLConnector::getInstance()->epp($query, $dict);

        if ($id_user === null) {
            foreach (array_keys(self::$models) as $key) {
                unset(self::$models[$key][$id_game]);
            }
        } else {
            unset(self::$models[$id_user][$id_game]);
        }
    }

    /**
     * delete user from game (also deletes notification infos)
     *
     * @param int $id_game
     * @param int $id_user
     * @return void
     */
    public static function leaveGame($id_user, $id_game) {
        self::deleteIsInGameInfos($id_user, $id_game);
        ModelInGamePhaseInfo::deleteInGamePhaseInfos($id_user, $id_game);
    }

    /**
     * checks if the given user is in the given game
     *
     * @param int $id_user
     * @param int $id_game
     * @return boolean
     */
    public static function isUserInGame($id_user, $id_game) {
        if (isset(self::$models[$id_user][$id_game])) {
            return true;
        }

        try {
            self::getIsInGameInfo($id_user, $id_game);
            return true;
        } catch (NullPointerException $ex) {
            return false;
        }
    }

    /**
     * changes the color if it is free
     *
     * @param $id_color int
     * @throws NullPointerException
     * @throws GameAdministrationException
     * @return void
     */
    public function setColor($id_color) {
        $id_color = intval($id_color);
        ModelColor::getModelColor($id_color);
        if (!ModelGame::getGame($this->id_game)->checkIfColorIsFree($id_color)) {
            throw new GameAdministrationException('Color already taken!');
        }
        $this->id_color = $id_color;
        SQLConnector::Singleton()->epp('set_user_in_game_color', array(':id_user' => $this->id_user, ':id_game' => $this->id_game, ':id_color' => $this->id_color));
    }

    /**
     * adds the given amount to the player money (can be negative)
     *
     * @param $money int/number
     * @return void
     */
    public function addMoney($money) {
        $money = intval($money);
        $this->money += $money;
        SQLConnector::Singleton()->epp('set_user_in_game_money', array(':id_user' => $this->id_user, ':id_game' => $this->id_game, ':money' => $this->money));
    }

    /**
     * sets the players money to given amount, must be greater or equal zero
     *
     * @param $money int/number
     * @return void
     */
    public function setMoney($money) {
        $money = intval($money);
        $money = max(0, $money);
        $this->money = $money;
        SQLConnector::Singleton()->epp('set_user_in_game_money', array(':id_user' => $this->id_user, ':id_game' => $this->id_game, ':money' => $this->money));
    }

    /**
     * sets the starting set for the user
     *
     * @param $id_set int
     * @throws NullPointerException
     * @return void
     */
    public function setStartingSet($id_set) {
        $id_set = intval($id_set);
        ModelStartSet::getSet($id_set);
        $this->id_set = $id_set;
        SQLConnector::Singleton()->epp('set_user_in_game_starting_set', array(':id_user' => $this->id_user, ':id_game' => $this->id_game, ':id_set' => $this->id_set));
    }

    /**
     * @return int
     */
    public function getIdUser() {
        return $this->id_user;
    }

    /**
     * @return int
     */
    public function getIdGame() {
        return $this->id_game;
    }

    /**
     * @return int
     */
    public function getIdColor() {
        return $this->id_color;
    }

    /**
     * @return ModelColor
     */
    public function getColor() {
        return ModelColor::getModelColor($this->id_color);
    }

    /**
     * @return int/number
     */
    public function getMoney() {
        return $this->money;
    }

    /**
     * @return int
     */
    public function getIdStartingSet() {
        return $this->id_set;
    }

    /**
     * @throws NullPointerException
     */
    public function resolve() {
        $this->user = ModelUser::getUser($this->id_user);
    }

    private function fill_member_vars() {
        $query = 'get_user_is_in_game';
        $dict = array();
        $dict[':id_user'] = $this->id_user;
        $dict[':id_game'] = $this->id_game;
        $result = SQLConnector::getInstance()->epp($query,$dict);
        if (empty($result)) {
            throw new NullPointerException('User is not in this game!');
        }

        $data = $result[0];
        $this->id_color = $data['id_color'];
        $this->money = $data['money'];
        $this->id_set = $data['id_set'];
    }

    /**
     * Specify data which should be serialized to JSON
     * @link https://php.net/manual/en/jsonserializable.jsonserialize.php
     * @return mixed data which can be serialized by <b>json_encode</b>,
     * which is a value of any type other than a resource.
     * @since 5.4.0
     */
    public function jsonSerialize()
    {
        return [
            'id_user' => $this->id_user,
            'id_game' => $this->id_game,
            'id_color' => $this->id_color,
            'money' => $this->money,
            'id_set' => $this->id_set,
            'user' => $this->user,
        ];
    }
}
