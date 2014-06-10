<?php
    class wechallFeed {

        private $app;
        private $outOfCompetition;

        public function __construct($app, $key) {
            if ($app->config('wechallAuthKey') != $key)
                throw new Exception('Invalid API key');

            $this->app = $app;
            $this->outOfCompetition = $app->config('wechallOutOfCompetition');
        }

        public function process() {
            if (!isset($_GET['action']))
                throw new Exception('Invalid request');

            switch ($_GET['action']) {
                case 'validatemail' : $this->validateMail(); break;
                case 'userscore' : $this->userScore(); break;
                default: throw new Exception('Invalid request');
            }
        }

        // Validate that a user owns an account on the site.
        // Ex request:
        // http://localhost:4242/?wechallFeed&key=42&action=validatemail&username=toto&email=foo@bar.co.uk
        // => 0 | 1
        public function validateMail(){
            if (!isset($_GET['username']) || !isset($_GET['email']))
                throw new Exception('Missing data fields');

            $st = $this->app->db->prepare('SELECT 1 FROM users
                                           WHERE users.username = :username and users.email = :email LIMIT 1');
            $st->execute(array(':username' => $_GET['username'], ':email' => $_GET['email']));
            echo $st->fetch() ? '1' : '0';
        }

        // Returns the users score on the site.
        // Ex Request:
        // http://localhost:4242/?wechallFeed&key=42&action=userscore&username=toto
        // => username:rank:score:maxscore:challssolved:challcount:usercount
        //MAXSCORE
        //$this->app->max_score
        //CHALLSOLVED
        //SELECT COUNT(*) AS `completed` FROM users_levels WHERE completed > 0 AND user_id = :userid
        //CHALLCOUNT
        //SELECT COUNT(*) AS challcount FROM levels
        //USERCOUNT
        //SELECT COUNT(*) AS usercount FROM users
        public function userScore(){
            if (!isset($_GET['username']))
                throw new Exception('Missing data fields');

            $outOfCompetqMarks = str_repeat('?, ', count($this->outOfCompetition) - 1) . '?';
            $outOfCompetSQL = count($this->outOfCompetition) > 0 ? " WHERE username NOT IN ($outOfCompetqMarks) " : '';
            $rankingParams = array_merge($this->outOfCompetition, $this->outOfCompetition);

            array_unshift($rankingParams, $this->app->max_score, $_GET['username']);
            array_push($rankingParams, $_GET['username']);

            $preSql = 'SET @rownum := 0;';
            $sql = '
                SELECT ladder.username, ladder.rank, ladder.score, ? AS maxscore,
                    (SELECT COUNT(*)
                    FROM users_levels
                    INNER JOIN users ON users_levels.user_id = users.user_id
                    WHERE completed > 0 AND username = ?) AS challsolved,

                    (SELECT COUNT(*) FROM levels) AS challcount,

                    (SELECT COUNT(*) FROM users ' .
                    $outOfCompetSQL .
                    ') AS usercount

                    FROM (
                        SELECT @rownum := @rownum + 1 AS rank, score, username
                        FROM users ' .
                        $outOfCompetSQL .
                        'ORDER BY score DESC, user_id ASC
                    ) AS ladder
                WHERE username = ? LIMIT 1 ';

            //Since PDO doesn't allow the execution of multiple statements, we have to set the var first.
            $this->app->db->exec($preSql);
            $st = $this->app->db->prepare($sql);

            $st->execute($rankingParams);
            $row = $st->fetch();

            echo $row->username, ':', $row->rank, ':', $row->score, ':',
                 $row->maxscore, ':', $row->challsolved, ':', $row->challcount, ':',
                 $row->usercount;
        }
    }

?>
