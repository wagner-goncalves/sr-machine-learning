<?php

    namespace SR\Processor;

	use Psr\Http\Message\ServerRequestInterface;
	use Psr\Http\Message\ResponseInterface;
	
    use Firebase\JWT\JWT;
	
	use Phpml\Classification\NaiveBayes;
	use Phpml\Classification\SVC;
	use Phpml\Classification\KNearestNeighbors;
	use Phpml\SupportVectorMachine\Kernel;

	class Processor{
		private $objLogger;
		private $objDownloader;
		private $algoritmosImplementados = ["NAIVEBAYES", "SVC", "KNN"]; //Algoritmos aceitos
		
		public function getLogger(){
			return $this->objLogger;
		}
		
		public function getDB(){
			return $this->container->db;
		}
		
		public function __construct($container){
			$this->container = $container;
		}

		protected function arrayToCsvDownload(array $array, $nomeArquivo = "") {
    
			if (count($array) == 0) {
				return null;
			}
			
			$filename = "data_export_" . date("Y-m-d") . ".csv";
			if($nomeArquivo != "") $filename = $nomeArquivo;
			
			// disable caching
			$now = gmdate("D, d M Y H:i:s");
			header("Expires: Tue, 03 Jul 2001 06:00:00 GMT");
			header("Cache-Control: max-age=0, no-cache, must-revalidate, proxy-revalidate");
			header("Last-Modified: {$now} GMT");
		 
			// force download  
			header("Content-Type: application/force-download");
			header("Content-Type: application/octet-stream");
			header("Content-Type: application/download");
		 
			// disposition / encoding on response body
			header("Content-Disposition: attachment;filename={$filename}");
			header("Content-Transfer-Encoding: binary");
		 
			$df = fopen("php://output", 'w');
			fputcsv($df, array_keys(reset($array)));
			foreach ($array as $row) {
				fputcsv($df, $row);
			}
			fclose($df);
			die();    
		}

		protected function ideologiaPartidariaTrainingData($idUsuario, $quiz, $instituicao, $flgMeusPoliticos, $csv = false){
			$objDB = $this->getDB();
			$sql = "CALL lmTrainingSetIdeologiaUsuario(?, ?, ?, ?)";
			$stmt = $objDB->pdo->prepare($sql);
			$stmt->bindParam(1, $idUsuario, \PDO::PARAM_INT);
			$stmt->bindParam(2, $instituicao, \PDO::PARAM_INT);
			$stmt->bindParam(3, $flgMeusPoliticos, \PDO::PARAM_BOOL);
			$stmt->bindParam(4, $quiz, \PDO::PARAM_INT);

			$rs = $stmt->execute();
			$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
			$stmt->closeCursor();

			$trainingLabels = []; //Ideologias
			$trainingFeatures = []; //Como votou cada político nas proposições (SIM, NÃO...)
			$contaPoliticos = 0;
			$politicoAtual = "";	
			
			//Prepara dados - Features = Proposições x Votação / Label = Espectro político
			for($i = 0; $i < count($rows); $i++){
					
				if($politicoAtual != $rows[$i]["oidPolitico"]){
					$politicoAtual = $rows[$i]["oidPolitico"];
					$contaPoliticos++;
					$trainingFeatures[$contaPoliticos - 1] = [];
					$trainingLabels[] = $rows[$i]["oidIdeologia"]; //Label
				}
				
				array_push($trainingFeatures[$contaPoliticos - 1], $rows[$i]["oidTipoNotificacao"]); //Amostras
			}		

			$detalheInstituicao = $this->container->db->get("instituicao", "*", ["oidInstituicao" => $instituicao]);


			if(count($trainingLabels) < $detalheInstituicao["minimoPredicao"]){
				throw new \Exception("Dados insuficientes para predição.");
			}

			return ["features" => $trainingFeatures, "labels" => $trainingLabels];
		}

		protected function ideologiaPartidariaPredictionData($idUsuario, $quiz, $instituicao){
			$objDB = $this->getDB();

			//Dados do comportamento do usuário para predição
			$quiz = intval($quiz);
			$sql = "CALL lmPredictionDataIdeologia(?, ?, ?)";
			$stmt = $objDB->pdo->prepare($sql);
			$stmt->bindParam(1, $idUsuario, \PDO::PARAM_INT);
			$stmt->bindParam(2, $instituicao, \PDO::PARAM_INT);
			$stmt->bindParam(3, $quiz, \PDO::PARAM_INT);
			$rs = $stmt->execute();
			$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
			$stmt->closeCursor();
			
			$predictFeatures = []; //Como o usuário votou
			
			for($i = 0; $i < count($rows); $i++){
				array_push($predictFeatures, $rows[$i]["oidTipoNotificacao"]); //Como votou
			}	
			
			return $predictFeatures;
		}

		protected function createClassifier($algoritmo){
		
			$classifier = null;
			if($algoritmo == 1){
				return new NaiveBayes();
			}else if($algoritmo == 2){
				//return new SVC(Kernel::LINEAR, $cost=1000);
				return new SVC(Kernel::RBF, $cost=1000, $degree = 3, $gamma = 6);
			}else if($algoritmo == 3){
				$classifier = new KNearestNeighbors();
			}
		}

		protected function train($algoritmo, $trainingFeatures, $trainingLabels){
			$classifier = $this->createClassifier($algoritmo);
			$classifier->train($trainingFeatures, $trainingLabels);
			return $classifier;
		}

		protected function predict($algoritmo, $trainingFeatures, $trainingLabels, $predictFeatures){
			$trainedClassifier = $this->train($algoritmo, $trainingFeatures, $trainingLabels);
			return $trainedClassifier->predict($predictFeatures);	
		}

		protected function joinFeaturesAndLabels($features, $labels){
			if(count($features) != count($labels)){
				throw new \Exception("Número de features diferente dos labels.");
			}

			for($i = 0; $i < count($features); $i++){
				array_push($features[$i], $labels[$i]);
			}
			return $features;
		}
		
        //Identifica a ideologia partidária de TODOS os usuários
		public function ideologiaPartidaria(ServerRequestInterface $request, ResponseInterface $response, array $args){
			$objDB = null;
			try{
				//Trata variáveis da requisição
				$oidAlgoritmo = $algoritmo = in_array($request->getParam("algoritmo"), $this->algoritmosImplementados) ? $request->getParam("algoritmo") : 1;
				$usuario = $request->getParam("id"); //Apenas de um usuário
				$csv = $request->getParam("csv"); //Apenas de um usuário
				$quiz = $request->getParam("quiz"); //Considerar proposicoes apenas de quiz específicos
				$flgMeusPoliticos = $request->getParam("meuspoliticos"); //Apenas politicos que usuário monitora - Caso contrário testa TODOS os políticos que interagiram com as mesmas proposições do usuário
				$flgMeusPoliticos = false;
				if($flgMeusPoliticos == "1") $flgMeusPoliticos = true;

				$instituicao = intval($request->getParam("instituicao")) > 0 ? $request->getParam("instituicao") : 1; //Considerar proposicoes apenas de instituicao específica - Default Câmara
				$detalheInstituicao = $this->container->db->get("instituicao", "*", ["oidInstituicao" => $instituicao]);


				$objDB = $this->getDB();
				$sql = "SELECT u.oidUsuario FROM usuario u";
				
				if($usuario > 0){
					$sql .= " WHERE u.oidUsuario = " . $usuario;
				}

				$usuarios = $this->container->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
				//$oidAlgoritmo = ($algoritmo == "NAIVEBAYES" ? 1 : ($algoritmo == "SVC" ? 2 : ($algoritmo == "KNN" ? 3 : "NULL")));  //Melhorar lógica

                $objDB->insert("afericao", ["oidAlgoritmo" => $oidAlgoritmo]);
				$oidAfericao = $objDB->id();
				
				foreach($usuarios as $usuario){

					$predictFeatures = $this->ideologiaPartidariaPredictionData($usuario["oidUsuario"], $quiz, $instituicao);
					$trainingData = $this->ideologiaPartidariaTrainingData($usuario["oidUsuario"], $quiz, $instituicao, $flgMeusPoliticos, false);
					$trainingFeatures = $trainingData["features"];
					$trainingLabels = $trainingData["labels"];
					$predictedIdeologia = $this->predict($algoritmo, $trainingFeatures, $trainingLabels, $predictFeatures);			

					//Salva resultados
					$objDB->insert("aboutideologiausuario", [
						"oidUsuario" => $usuario["oidUsuario"], 
						"oidIdeologia" => ($predictedIdeologia == "" ? "null" : $predictedIdeologia),
						"oidAfericao" => $oidAfericao,
						"oidQuizVotacao" => ($quiz == "0" ? null : $quiz)
					]);
					
					$error = $objDB->error();
					if(intval($error[0]) > 0){ 
						echo $sql;
						print_r($error);
						throw new \Exception("Erro ao processar predição.");						
					}
					
					if(count($predictFeatures) >= $detalheInstituicao["minimoPredicao"]){	
						$ideologias[] = $predictedIdeologia; 
					}

					//Download
					if($csv == "1"){
						$this->arrayToCsvDownload($this->joinFeaturesAndLabels($trainingFeatures, $trainingLabels), sprintf("training-data-ideologia-%s.csv", date("d-m-Y")));
					}
					
					//print_r($trainingLabels);
					//print_r($trainingFeatures);
					//print_r($predictFeatures);

				}
				return $response->withJson(["success" => true, "ideologia" => $ideologias, "quantidadeInteracoes" => count($predictFeatures), "interacoesRestantes" => ($detalheInstituicao["minimoPredicao"]) - count($predictFeatures)]);
			}catch(\Exception $e){
                return $response->withStatus(500)->withJson(["success" => false, "message" => $e->getMessage()]);		
			}
		}
		
        //Identifica o político favorito de TODOS os usuários
		public function politicoFavorito(ServerRequestInterface $request, ResponseInterface $response, array $args){
			$objDB = null;
			try{

				$objDB = $this->getDB();

				//Parâmetros de entrada
				$oidAlgoritmo = $algoritmo = $request->getParam("algoritmo") ? $request->getParam("algoritmo") : 1;
				$usuario = intval($request->getParam("id"));
				$quiz = $request->getParam("quiz"); //Considerar proposicoes apenas de quiz específico
				$instituicao = intval($request->getParam("instituicao")) > 0 ? $request->getParam("instituicao") : 1; //Considerar proposicoes apenas de instituicao específica - Default Câmara
				$flgMeusPoliticos = $request->getParam("meuspoliticos") == "1" ? true : false; //Apenas politicos que monitoro
			
				$detalheInstituicao = $this->container->db->get("instituicao", "*", ["oidInstituicao" => $instituicao]);

				$favoritos = [];

				//if(!in_array($algoritmo, $this->algoritmosImplementados)) throw new \Exception("Não implementado");
				//$oidAlgoritmo = ($algoritmo == "NAIVEBAYES" ? 1 : ($algoritmo == "SVC" ? 2 : ($algoritmo == "KNN" ? 3 : "NULL")));  //Melhorar lógica
				
				$sql = "SELECT u.oidUsuario FROM usuario u";
				if($usuario > 0) $sql .= " WHERE u.oidUsuario = " . $usuario;
				$usuarios = $this->container->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
				
                $objDB->insert("afericao", ["oidAlgoritmo" => $oidAlgoritmo]);
                $oidAfericao = $objDB->id();
				
				foreach($usuarios as $usuario){

					//Dados de treinamento
					$quiz = intval($quiz);
					$sql = "CALL lmTrainingSetIdeologiaUsuario(?, ?, ?, ?)";
					$stmt = $objDB->pdo->prepare($sql);
					$stmt->bindParam(1, $usuario["oidUsuario"], \PDO::PARAM_INT);
					$stmt->bindParam(2, $instituicao, \PDO::PARAM_INT);
					$stmt->bindParam(3, $flgMeusPoliticos, \PDO::PARAM_BOOL);
					$stmt->bindParam(4, $quiz, \PDO::PARAM_INT);
					$rs = $stmt->execute();
					$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
					$stmt->closeCursor();
					
					$trainingLabels = []; //Políticos
					$trainingFeatures = []; //Como votou
					$contaPoliticos = 0;
					$politicoAtual = "";

					for($i = 0; $i < count($rows); $i++){
						
						if($politicoAtual != $rows[$i]["oidPolitico"]){
							$politicoAtual = $rows[$i]["oidPolitico"];
							$contaPoliticos++;
							$trainingFeatures[$contaPoliticos - 1] = [];
							$trainingLabels[] = $rows[$i]["oidPolitico"]; //Label
						}

						if($algoritmo == 1){
							//Cria variável categórica
							$tipo = "";
							switch($rows[$i]["oidTipoNotificacao"]){
								case "3" : $tipo = "Sim"; break;
								case "4" : $tipo = "Não"; break;
								case "5" : $tipo = "Ausencia"; break;
								case "6" : $tipo = "Abstenção"; break;
								case "7" : $tipo = "Obstrução"; break;
								case "8" : $tipo = "Não-Votou"; break;
								case "0" : $tipo = "Não-Participou"; break;
							}

							$rows[$i]["oidTipoNotificacao"] = $tipo;
						}
						
						array_push($trainingFeatures[$contaPoliticos - 1], $rows[$i]["oidTipoNotificacao"]); //Amostras
					}

					$classifier = null;

					if($algoritmo == 1){
						$classifier = new NaiveBayes();
					}else if($algoritmo == 2){
						//$classifierSVC = new SVC(Kernel::LINEAR, $cost=1000);
						$classifier = new SVC(Kernel::RBF, $cost=1000, $degree = 3, $gamma = 6);
					}else if($algoritmo == 3){
						$classifier = new KNearestNeighbors();
					}

					//print_r($trainingLabels);
					//print_r($trainingFeatures);
					
					$classifier->train($trainingFeatures, $trainingLabels);

					$quiz = intval($quiz);
					//Dados do comportamento do usuário para predição
					$sql = "CALL lmPredictionDataIdeologia(?, ?, ?)";
					$stmt = $objDB->pdo->prepare($sql);
					$stmt->bindParam(1, $usuario["oidUsuario"], \PDO::PARAM_INT);
					$stmt->bindParam(2, $instituicao, \PDO::PARAM_INT);
					$stmt->bindParam(3, $quiz, \PDO::PARAM_INT);
					$rs = $stmt->execute();
					$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
					$stmt->closeCursor();
					
					$predictFeatures = []; //Como o usuário votou
					
					for($i = 0; $i < count($rows); $i++){

						if($algoritmo == 1){
							//Cria variável categórica
							$tipo = "";
							switch($rows[$i]["oidTipoNotificacao"]){
								case "3" : $tipo = "Sim"; break;
								case "4" : $tipo = "Não"; break;
								case "5" : $tipo = "Ausencia"; break;
								case "6" : $tipo = "Abstenção"; break;
								case "7" : $tipo = "Obstrução"; break;
								case "8" : $tipo = "Não-Votou"; break;
								case "0" : $tipo = "Não-Participou"; break;
							}
							$rows[$i]["oidTipoNotificacao"] = $tipo;
						}


						array_push($predictFeatures, $rows[$i]["oidTipoNotificacao"]); //Como votou
					}
					
					//print_r($trainingLabels);
					//print_r($trainingFeatures);
					//print_r($predictFeatures);

					$predictedPolitico = $classifier->predict($predictFeatures);
					//$predictedProb = $classifier->predict_prob($predictFeatures);							
					//echo $predictedPolitico;

					$objDB->insert("aboutpoliticousuario", [
						"oidUsuario" => $usuario["oidUsuario"], 
						"oidPolitico" => ($predictedPolitico == "" ? "null" : $predictedPolitico),
						"oidAfericao" => $oidAfericao,
						"oidQuizVotacao" => ($quiz == 0 ? null : $quiz)
					]);

					$oidAbout = $objDB->id();  
					
					//Aferição para uma instituição específica
					if($instituicao > 0){
						$objDB->insert("aboutpoliticousuarioinstituicao", [
							"oidAboutPoliticoUsuario" => $oidAbout, 
							"oidInstituicao" => $instituicao
						]);
					}
					
					$error = $objDB->error();
					if(intval($error[0]) > 0){
						echo $sql;
						print_r($error);
						throw new \Exception("Erro ao processar predição.");
					}
				
					if(count($predictFeatures) >= $detalheInstituicao["minimoPredicao"]){						
						$favoritos[] = $predictedPolitico;
					}

				}
				return $response->withJson(["success" => true, "politico" => $favoritos, "quantidadeInteracoes" => count($predictFeatures), "interacoesRestantes" => ($detalheInstituicao["minimoPredicao"]) - count($predictFeatures)]);
			}catch(\Exception $e){
                return $response->withStatus(500)->withJson(["success" => false, "message" => $e->getMessage()]);		
			}
		}        
        
        //Identifica a ideologia partidária de UM os usuário
		public function ideologiaPartidariaUsuario(ServerRequestInterface $request, ResponseInterface $response, array $args){
			try{
				return $this->ideologiaPartidaria($request, $response, $args);
			}catch(\Exception $e){
                return $response->withStatus(500)->withJson(["success" => false, "message" => $e->getMessage()]);		
			}			
		}
		
        //Identifica o politico favorito de UM os usuário
		public function politicoFavoritoUsuario(ServerRequestInterface $request, ResponseInterface $response, array $args){
			try{
				return $this->politicoFavorito($request, $response, $args);
			}catch(\Exception $e){
                return $response->withStatus(500)->withJson(["success" => false, "message" => $e->getMessage()]);		
			}	
		}
	}
