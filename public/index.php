<?php
	require '../vendor/autoload.php';
	
    set_time_limit(60 * 30);

    use \SR\Config\Config;

    //Recupera variáveis do ambiente
    $dotenv = new Dotenv\Dotenv(__DIR__ . "/../private/", ".config");
    $dotenv->load();

	$app = new \Slim\App(Config::getAppSettings());
    Config::setContainer($app->getContainer());

	$app->group("/v1/processa", function() use ($app){
		
		$app->get('/ideologia-partidaria-usuario', 'SR\Processor\Processor:ideologiaPartidariaUsuario');
		$app->get('/ideologia-partidaria/{algoritmo}', 'SR\Processor\Processor:ideologiaPartidaria');
		
		$app->get('/politico-favorito-usuario', 'SR\Processor\Processor:politicoFavoritoUsuario');      
		$app->get('/politico-favorito/{algoritmo}', 'SR\Processor\Processor:politicoFavorito');      		
	});

	$app->run();
?>
