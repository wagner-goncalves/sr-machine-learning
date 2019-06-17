<?php
    namespace SR\Config;

	class Config{
        
		public static function ambienteDesenvolvimento(){
			$servidor = $_SERVER['SERVER_NAME'];
			if (strpos($servidor, 'desenv') !== false || strpos($servidor, 'dev') !== false || strpos($servidor, 'local') !== false) return true;
			else return false;
		}            
        
		public static function getAppSettings(){
			return [
				'settings' => [
                    'displayErrorDetails' => getenv("DISPLAY_ERROR_DETAILS"), // set to false in production
            
                    // Renderer settings
                    'renderer' => [
                        'template_path' => realpath("../") . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR
                    ], 
			
					/*
					// Monolog settings
					'logger' => [
						'name' => 'slim-app',
						'path' => realpath(".") . DIRECTORY_SEPARATOR . "logs" . DIRECTORY_SEPARATOR . "app.log"// __DIR__ . '/../logs/app.log',
					],
					*/
                   	
				],
			];	
		}
        
        public static function getRendererSettings(){
            return [
                'template_path' => realpath("../") . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR,
            ];
        }        
		
		public static function setContainer($container){
            
			// view renderer
			$container['renderer'] = function ($c) {
				$settings = Config::getRendererSettings();
				return new Slim\Views\PhpRenderer($settings['template_path']);
			};
            
			// view renderer
            $container['db'] = function ($c) {
                $settings = Config::getDatabaseSettings();
                $db = new \Medoo\Medoo(Config::getDatabaseSettings());
                
                if(!$db){ 
                    throw new Exception();
                }else{ 
                    return $db;
                }
            };            
			
			/*
			// monolog
			$container['logger'] = function ($c) {
				$settings = $c->get('settings')['logger'];
				$logger = new \Monolog\Logger($settings['name']);
				$logger->pushProcessor(new \Monolog\Processor\UidProcessor());
				$logger->pushHandler(new \Monolog\Handler\StreamHandler($settings['path'], \Monolog\Logger::DEBUG));
				return $logger;
			};
			*/
		}
        
        public static function getDatabaseSettings(){
            
			if(Config::ambienteDesenvolvimento()) return [
				'database_type' => 'mysql',
                'server' => getenv("DATABASE_SERVER"),
                'username' => getenv("DATABASE_USER"),
                'password' => getenv("DATABASE_PASSWORD"),
                'database_name' => getenv("DATABASE_NAME"),
                'charset' => getenv("DATABASE_CHARSET"),
            ];
			else return [
                'database_type' => 'mysql',
				'server' => getenv("DATABASE_SERVER_PRODUCAO"),
                'username' => getenv("DATABASE_USER_PRODUCAO"),
                'password' => getenv("DATABASE_PASSWORD_PRODUCAO"),
                'database_name' => getenv("DATABASE_NAME_PRODUCAO"),
                'charset' => getenv("DATABASE_CHARSET_PRODUCAO"),
            ];            
        }           
        
	}

?>