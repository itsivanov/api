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
      "host"        => "localhost",
      "username"    => "root",
      "password"    => "",
      "dbname"      => "univermag",
      "options"     => [
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


//для тестирования запроса АПИ

$app->get('/test', function () use ($app) {

  $input = array(
  	'hash'        => '222',
  	'status'      => 2,
  	'oid'         => 370945,
  	'id'          => 788,
  	'status2'     => 4,
  	'comment'     => 'hello'
  );

  $url = 'http://api.local/set/status';
  $json = json_encode($input);
  $headers = array(
    'Content-Type: application/json',
    'Content-Length: ' . strlen($json),
  );

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
  curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

  $result = curl_exec($ch);

  echo json_encode($result);
  //var_dump($result);
  //echo $app['view']->render('test');
  });


// notFound
$app->notFound(function() use ($app) {

	$app->response->setJsonContent(
		array(
			'status'  => 405,
			'message' => 'Method Not Allowed',
		)
	);

	return $app->response;
});

$app->get('/get/orders', function () use ($app) {

	global $db;
	$filter 		= new Filter();
	$get 			= $app->request->getQuery();

	if (!hash_is_valid($get['hash'])) {
		$app->response->setJsonContent(
			array(
				'status'  => 401,
				'message' => 'Unauthorized',
			)
		);

	return $app->response;
}

	$offer_owner = hash_is_valid($get['hash']);

	$status 		= '200';
	$message 		= 'OK';
	$hash 			= $get['hash']; //ключ API
	$get_id 		= $get['id']; // выборка по id заказа
	$get_oid 	    = $get['o']; // выборка по id заказа в таблице рекламодателя
	$offer_id 	= $get['i']; // выборка по offer_id
	$fields 		= $get['f']; // выборка по fields
	$created		= $get['c']; //выборка по дате
	$limit 			= $get['limit']; // лимит по выборке

    // Список условий
	$conditions = [
		"offer_owner = :offer_owner"
	];

	// Список параметров, которые будут подставлены в условия
	$parameters = [
		":offer_owner" => [
			"type"  => PDO::PARAM_INT,
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
				"type"  => "int" // тип значения, необходим для фильтрации
			],
			"country_code" => [
				"field" => "country_code",
				"type"  => "string"
			],
			'status2' => [
				'field' => 'status2',
				"type"  => "int"
				],
			'phone'	=>[
				'field' => 'phone',
				"type"  => "string"
			],
			'amount' => [
				'field' => 'amount',
				"type"  => "int"
			],
			'target' =>[
				'field' => 'target',
				"type"  => "int"
			]
		];

		// Обрабатываем массив фильтров
		// !! Get не принимает символ '+', поэтому поиск по номеру телефона выполняется только тех у которых // нет плюса !!
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
					$value    = $filter->sanitize($value, "int");
					$pdo_type = PDO::PARAM_INT;
				} else {
					$value = $filter->sanitize($value, ["string", "striptags", "trim"]);
					$pdo_type = PDO::PARAM_STR;
				}
				$conditions[] = "{$table_field} = :{$table_field}";


				$parameters[":{$table_field}"] = [
					"value" => $value,
					"type"  => $pdo_type
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
		 		"type"  => "int",
		 		"value" => $initial
		 	];

		 	$parameters[":final"] = [
		 		"type"  => "int",
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

    // var_dump($parameters);die;
	$stmt = $db->prepare($query);
	if (!empty($parameters)) {
		foreach ($parameters as $param_name => $values) {
			$type = $values["type"] == "int" ? PDO::PARAM_INT : PDO::PARAM_STR;
			$stmt->bindParam($param_name, $values["value"], $type);
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
  });


//изменение статуса
$app->post('/set/status', function () use ($app) {

	global $db;

    $filter = new Filter;
	$post = $app->request->getJsonRawBody();

	// получаем id рекламодателя
	$advertiser_id = hash_is_valid($post->hash);

	if (!$advertiser_id) {
		$app->response->setJsonContent(
			array(
				'status' => 401,
				'message' => 'Unauthorized',
			)
		);

		return $app->response;
	}

	$errors = [];
	$oid_set     = $filter->sanitize($post->oid, 'int');
    $id_set      = $filter->sanitize($post->id, 'int');
    $status_set  = $filter->sanitize($post->status, 'int');
    $status2_set = $filter->sanitize($post->status2, 'int');
    $comment_set = $filter->sanitize($post->comment, ['string', "striptags", "trim"]);

    // check id
    if (!empty($id_set)) {

        $query = "SELECT oid
        		  FROM `orders`
        		  WHERE `id` = :id AND offer_owner = :offer_owner";
       	$stmt = $db->prepare($query);
        $stmt->bindParam(':offer_owner', $advertiser_id, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id_set, PDO::PARAM_INT);
        $r = $stmt->execute();

        if ($stmt->rowCount() == 1) {
        	$oid = $stmt->fetchColumn();
        	if (empty($oid)) {
        		$oid = $oid_set;
        	}
        } else {
        	$errors[] = "The [id] is not valid";
        }
        // если id заказа валидный - вернет 1 строку, в другом случае - 0 строк.
    }

    // check oid
    if (empty($oid_set)) {
    	$errors[] = "The [oid] is not valid";
    }

    $status2_values = [
		0 => "Недозвон",
		1 => "Некорректный телефон",
		2 => "Отказ",
		3 => "Перезвонить",
		4 => "Повтор",
		5 => "Нет в наличии",
		6 => "Перезаказ",
		7 => "Ошибочные данные",
		8 => "Тест",
		9 => "Некорректные данные",
		10 => "Доставка невозможна",
		11 => "Сервис",
		12 => "На модерации",
		13 => "В обработке",
	];

	// check status2
	if (isset($status2_values[$status2_set])) {
        $status2_name = $status2_values[$status2_set];
	} else {
	    $errors[] = 'The [status2] is not valid';
	}


	$status_values = [
        0 => 'В обработке',
        1 => 'Подтвержден',
        2 => 'Аннулирован',
        3 => 'Забран',
        4 => 'Возврат'
    ];

    // check status
    if (isset($status_values[$status_set])) {
        $status = $status_values[$status_set];
        if (empty($status2_name)) {
        	$status2_name = $status;
        }
    } else {
        $errors[] = 'The [status] is not valid';
	}

    if (empty($errors)){

        $time = time();

        // update status
        $query =  "UPDATE orders
                   SET status = :status, status2 = :status2, status2_name = :status2_name, comment = :comment, status_upd = 1, oid = :oid, changed = :time
                   WHERE id = :id ";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id_set, PDO::PARAM_INT);
        $stmt->bindParam(':status', $status_set, PDO::PARAM_INT);
        $stmt->bindParam(':status2', $status2_set, PDO::PARAM_INT);
        $stmt->bindParam(':status2_name', $status2_name, PDO::PARAM_STR);
        $stmt->bindParam(':comment', $comment_set, PDO::PARAM_STR);
        $stmt->bindParam(':oid', $oid, PDO::PARAM_INT);
        $stmt->bindParam(':time', $time, PDO::PARAM_INT);
        $stmt->execute();

        // write log
        $query = "INSERT INTO orders_logs (order_id, status_name, comment, created)
                  VALUES (:oid, :status, :comment, :created)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':oid', $oid, PDO::PARAM_INT);
        $stmt->bindParam(':status', $status2_name, PDO::PARAM_STR);
        $stmt->bindParam(':comment', $comment_set, PDO::PARAM_STR);
        $stmt->bindParam(':created', $time, PDO::PARAM_STR);
        $result = $stmt->execute();

        $app->response->setJsonContent(
			array(
				'status' => 200,
				'message' => 'OK',
			)
        );
        pa($response);

        // return $app->response;

    } else {
        // ошибка при не верных параметрах
        $app->response->setJsonContent(
			array(
				'status' => 409,
				'message' => 'Conflict',
				'errors' => $errors
			)
        );
        pa($response);
        // return $app->response;
    }
});

	return $app->handle();

} catch (PDOException $e) {
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

	return 202;

	// return true;
	$di = new FactoryDefault();

	$db = new PdoMysql([
	      "host"       => "localhost",
	      "username"   => "root",
	      "password"   => "",
	      "dbname"     => "api",
	      "options"    => [
	        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES UTF8",
	      ]
	]);

	$di->set('db', $db);

	if (empty($hash)) {
		return FALSE;
	}

	$filter = new Filter;

	$hash = $filter->sanitize($hash, 'string');
	$hash = $filter->sanitize($hash, 'striptags');
	$hash = $filter->sanitize($hash, 'trim');

	$query = "SELECT id_user FR  OM hash WHERE name = ?";

	return $db->fetchColumn($query, [$hash]);
}

// обработка параметра массивов
function getArray ($string) {
	$ids = trim($string, '()');
	$ids = explode(",", $ids);

	return $ids;
}
