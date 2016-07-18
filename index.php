<?php

use Phalcon\Filter;
use Phalcon\Mvc\Micro;
use Phalcon\Mvc\View\Simple;
use Phalcon\DI\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Mysql as PdoMysql;

try {
	// di
	$di = new FactoryDefault();

  $db = new PdoMysql([
      "host" => "localhost",
      "username" => "root",
      "password" => "",
      "dbname" => "univermag",
      "options"  => [
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES UTF8",
      ]
  ]);

  $di->set('db', $db);

	// di_view
	$di->set('view', function() {
		$view = new Simple();
		$view->setViewsDir(__DIR__ . '/app/views/');
		return $view;
	});

	// app
	$app = new Micro($di);

	// :: help
	$app->get('/help', function() use ($app) {
		echo $app['view']->render('help');
	});

  $app->get('/', function () use ($app) {
     echo "<h1>API Univer-Mag!</h1>";
  });

	//для тестирования запроса АПИ
  $app->get('/test', function () use ($app) {
    echo $app['view']->render('test');
  });

	$app->notFound(function () use ($app) {
	    $app->response->setStatusCode(404, "Not Found")->sendHeaders();

	    echo 'Ошибка.Страницы не существует';
	});


  $app->get('/get/orders', function () use ($app) {

		global $db;
		$filter 		= new Filter();
    	$get 			= $app->request->getQuery();


		if (!hash_is_valid($get['hash'])) {
			$app->response->setJsonContent(
				array(
					'status' => 401,
					'message' => 'Unauthorized',
				)
			);
			return $app->response;
		}


		$offer_owner = getIdUser($get['hash']);

		$status 		= '200';
		$message 		= 'OK';
		$hash 			= $get['hash']; //ключ API
		$get_id 		= $get['id']; // выборка по id заказа
		$get_oid 	    = $get['o']; // выборка по id заказа в таблице рекламодателя
		$offer_id 		= $get['i']; // выборка по offer_id
		$fields 		= $get['f']; // выборка по fields
		$created		= $get['c']; //выборка по дате
		$limit 			= $get['limit']; // лимит по выборке


    echo "<h1>Список заказов</h1>";

    // Список условий
	$conditions = [
		"offer_owner = :offer_owner"
	];

	// Список параметров, которые будут подставлены в условия
	$parameters = [
		":offer_owner" => [
			"type" => PDO::PARAM_INT,
			"value" => $offer_owner
		],
	];

	// get:id
    if (!empty($get['id'])) {
		$ids = getArray($get['id']);
		$ids = $filter->sanitize($ids, "int");

		if (!empty($ids)) {
			$conditions[] = "id IN (" . implode(",", $ids) . ")";
		}
    }

    // get:o
    if (!empty($get['o'])) {
		$ids = getArray($get['o']);
		$ids = $filter->sanitize($ids, "int");

		if (!empty($ids)) {
			$conditions[] = "oid IN (" . implode(",", $ids) . ")";
		}
    }

	// get:offer_id
    if (!empty($get['i'])) {
		$ids = getArray($get['i']);
		$ids = $filter->sanitize($ids, "int");

		if (!empty($ids)) {
			$conditions[] = "offer_id IN (" . implode(",", $ids) . ")";
		}
    }
    // get:fields

	if (!empty($get['f'])) {

		// Перечисление полей, по которым может идти выборка. Выборку по commission делать не нужно.
		$accepted = [
			"status" => [
				"field" => "status",  // название сответствующего поля в бд
				"type" => "int" // тип значения, необходим для фильтрации
			],
			"country_code" => [
				"field" => "country_code",
				"type" => "string"
			],
			'status2' => [
				'field' => 'status2_name',
				"type" => "string"
				],
			'phone'	=>[
				'field' => 'phone',
				"type" => "integer"
			],
			'amount' => [
				'field' => 'amount',
				"type" => "integer"
			],
			'target' =>[
				'field' => 'target',
				"type" => "integer"
			]
		];

		// Обрабатываем массив фильтров
		$f_list = getArray($get['f']);

		foreach ($f_list as $a) {
			$temp = explode(":", $a);
			$name = $temp[0];
			$value = $temp[1];

			// Если фильтр входит в список допустимых - добавить его в запрос
			if (array_key_exists($name, $accepted)) {
				$type 		 = $accepted[$name]["type"];
				$table_field = $accepted[$name]["field"];

				if ($type == "int") {
					$value = $filter->sanitize($value, "int");
					$pdo_type = PDO::PARAM_INT;
				} else {
					$value = $filter->sanitize($value, ["string", "striptags", "trim"]);
					$pdo_type = PDO::PARAM_STR;
				}
				$conditions[] = "{$table_field} = :{$table_field}";


				$parameters[":{$table_field}"] = [
					"value" => $value,
					"type" => $pdo_type
				];
			}
		}
	}

	// get:created
	if (!empty($get['c'])) {

		$c = getArray($get['c']);
		$initial = !empty($c[0]) ? $filter->sanitize($c[0], "int") : FALSE ;
		$final 	 = !empty($c[1]) ? $filter->sanitize($c[1], "int") : FALSE ;

		if (!empty($initial) && !empty($final)) {

		 	$conditions[] = "created BETWEEN :initial AND :final";

		 	$parameters[":initial"] = [
		 		"type" => "int",
		 		"value" => $initial
		 	];

		 	$parameters[":final"] = [
		 		"type" => "int",
		 		"value" => $final
		 	];
		}
	}

	$query =  "SELECT first_name, last_name, phone, country_code, ip, user_id, id, oid, amount, commission + 			webmaster_commission AS commission, c.currency, target, status, status2, created
               FROM orders o LEFT JOIN country c ON c.code = o.country_code";

    if (!empty($conditions)) {
    	$query .= " WHERE " . implode(" AND ", $conditions);
    }

	//get:limit
	if (!empty($get['limit']) && $get['limit'] > 0) {
		$limit = $filter->sanitize($get['limit'], "int");
		$query .= " LIMIT {$limit}";
	}

	$stmt = $db->prepare($query);

	if (!empty($parameters)) {
		foreach ($parameters as $param_name => &$values) {
			$stmt->bindParam($param_name, $values["value"], $values["type"]);
		}
	}

	$result = $stmt->execute();
	if ($result === TRUE) {

		$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$response = [
	    	'status'  => $status,
	        'message' => $message,
	        'data'    => $data
	    ];

	    $app->response->setJsonContent($response, JSON_UNESCAPED_UNICODE);
	    return $app->response;

	} else {
		// вернуть код ошибки
	}

    // echo json_encode($result_fin); // ответ в формате json
    // pa($result_fin);
  });

	//изменение статуса
	$app->get('/set/status', function () use ($app) {

		// global $db;

		$post = $app->request->getJsonRawBody();
		// get:hash
		if (!hash_is_valid($get['hash'])) {
			$app->response->setJsonContent(
				array(
					'status' => 401,
					'message' => 'Unauthorized',
				)
			);
			return $app->response;
		}

	});

	return $app->handle();

}catch (PDOException $e) {
	echo $e->getMessage();
}


function pa($mixed, $stop = false) {
	$ar = debug_backtrace();
	$key = pathinfo($ar[0]['file']);
	$key = $key['basename'] .':'. $ar[0]['line'];
	$print = array($key => $mixed);
	echo '<pre>'. print_r($print, 1) .'</pre>';
	if ($stop == 1) exit();
}
//
function hash_is_valid($hash) {
	global $db;

	return TRUE;

	if (empty($hash)) {
		return FALSE;
	}

	$filter = new Filter;

	$hash = $filter->sanitize($hash, 'string');
	$hash = $filter->sanitize($hash, 'striptags');
	$hash = $filter->sanitize($hash, 'trim');

	$query = "SELECT name FROM hash WHERE name = ?";
	return $db->fetchColumn($query, [$hash]) == $hash;
}


function getIdUser($hash)
{
	global $db;

	return 202;

	$query = "SELECT id_user FROM hash WHERE name = ?";
	return $db->fetchColumn($query, [$hash]);
}


// обработка параметра массивов
function getArray ($string) {

	$filter = new Filter();
	$ids = trim($string, '()');
	$ids = explode(",", $ids);

	return $ids;
}
