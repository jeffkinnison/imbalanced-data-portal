<?php

namespace ODE\AnalysisBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use ODE\AnalysisBundle\Entity\Result;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class DefaultController extends Controller
{
    public function runAnalysisAction(Request $request){
        $model_id = $request->query->get('model');
        $dataset_id = $request->query->get('dataset');

        // Retrieve all GET parameters and remove from the list some that are going to be saved on a different column in the table
        $params = $request->query->all();
        unset($params['dataset'],$params['model'],$params['acceptTerms']);

        $em = $this->getDoctrine()->getManager();
        $model = $em->getRepository('ODEAnalysisBundle:Model')->find($model_id);
        $dataset = $em->getRepository('ODEDatasetBundle:Dataset')->find($dataset_id);

        // Retrieve from model table a mapping parameter->parameter_type
        $model_params = $model->getParameters();
        $preprocessing_params_types = array(
            'undersampling' => 'bool',
            'undersampling_rate' => 'int',
            'oversampling' => 'bool',
            'oversampling_percentage' => 'int',
            'missing_data' => 'string',
            'pca' => 'bool',
            'n_components' => 'int',
            'standardization' => 'bool',
            'normalization' => 'bool',
            'norm' => 'string',
            'binarization' => 'bool',
            'binarization_threshold' => 'float',
            'outlier_detection' => 'bool'
        );
        $preprocessing_params = array();

        foreach (array_keys($params) as $param){
            if (array_key_exists($param,$preprocessing_params_types)){
                if ($preprocessing_params_types[$param] == 'int'){
                    $preprocessing_params[$param] = (int)$params[$param];
                }elseif (($preprocessing_params_types[$param] == 'float')){
                    $preprocessing_params[$param] = (float)$params[$param];
                }elseif (($preprocessing_params_types[$param] == 'bool')){
                    $preprocessing_params[$param] = (int)$params[$param];
                }
                else{
                    $preprocessing_params[$param] = $params[$param];
                }
                unset($params[$param]);
            }
        }

        // Check to see if parameter list is empty (e.g., Naive Bayes)
        // If so, pass an empty object to represent that
        if (!count((array)$params)){
            $params =  (object) null;
        }
        // Convert string values from GET to actual numbers so that python script won't freak out
        else{
            foreach (array_keys($params) as $param){
                if ($model_params[$param] == 'int'){
                    $params[$param] = (int)$params[$param];
                }elseif (($model_params[$param] == 'float')){
                    $params[$param] = (float)$params[$param];
                }elseif (($model_params[$param] == 'bool')){
                    $params[$param] = (int)$params[$param];
                }
            }
        }

        // Create an entry with that data in the ode_results table
        $result = new Result();
        $result->setUser($this->getUser());
        $result->setModel($model);
        $result->setDataset($dataset);
        $result->setPreprocessing_params($preprocessing_params);
        $result->setParams($params);
        //$result->setModel_name($model_name);

        $em->persist($result);
        $em->flush();

        // Once all parameters for the experiment are stored, call python script to actually run it
        $this->runAnalysis($result->getId());

        // Once script is called, redirect user to "waiting" page
        return $this->redirect($this->generateUrl('ode_wait_result', array('id' => $result->getId()),true));
    }

    private function runAnalysis($id){
        // Python script needs to know the "current_dir" to open data files
        $script = __DIR__.'/../../../../web/assets/scripts/run_analysis.py';
        $current_dir = __DIR__.'/../../../../web/assets/scripts/';

        // To prevent blocking, use the code below when running the python script
        // Note that windows doesn't like '&' so we use pclose() for the time being
        // TODO: Edit this code when deploying to production server
        if (substr(php_uname(), 0, 7) == "Windows"){
            $terminal_output = pclose(popen('start /B python '.$script.' '.$id.' '.$current_dir, "r"));
        }  else {
            $terminal_output = exec('python '.$script.' '.$id.' '.$current_dir.' &');
        }
    }
    public function waitForAnalysisResultAction(Request $request){
        return $this->render('@ODEAnalysis/Default/waitResult.html.twig', array('id' => $request->query->get('id')));
    }

    public function checkIfAnalysisIsDoneAction(Request $request){
        $result_id = $request->query->get('id');
        $em = $this->getDoctrine()->getManager();
        $result = $em->getRepository('ODEAnalysisBundle:Result')->find($result_id);

        // Return 0 or 1 from database indicating whether or not analysis finished running
        return new Response(json_encode(array('finished'=>$result->getFinished())));
    }

    public function generateReportAction(Request $request){
        $result_id = $request->query->get('id');
        $em = $this->getDoctrine()->getManager();
        $result = $em->getRepository('ODEAnalysisBundle:Result')->find($result_id);

        $report_data = $result->getReport_data();

        // Get the rank for the current execution based on all accuracies previously obtained for the same dataset
        // TODO: Change this syntax to the safer http://doctrine-orm.readthedocs.org/en/latest/reference/native-sql.html
        $query = "SELECT count(*)+1 as rank from (select accuracy from ode_results WHERE dataset_id=".$result->getDataset()->getID()." group by accuracy) as A WHERE A.accuracy > ".$result->getAccuracy();
        $connection = $em->getConnection()->query($query);
        $connection->execute();
        $rank = $connection->fetchAll()[0]['rank'];

        return $this->render('@ODEAnalysis/Default/report.html.twig',
            array(
                'auroc' => $result->getAuroc(),
                'aupr' => $result->getAupr(),
                'roc_points' => $report_data['roc_points'],
                'prc_points' => $report_data['prc_points'],
                'confusion_matrix' => explode(",",$report_data['confusion_matrix']),
                'classification_report' => explode(",",$report_data['classification_report']),
                'indexes' => explode(",",$report_data['indexes']),
                'y_original_values' => explode(",",$report_data['y_original_values']),
                'y_pred' => explode(",",$report_data['y_pred']),
                'errors' => explode(",",$report_data['errors']),
                'y_prob' => explode(",",$report_data['y_prob']),
                'model' => $em->getRepository('ODEAnalysisBundle:Model')->find($result->getModel())->getName(),
                'dataset_name' => $result->getDataset()->getName(),
                'runtime' => $result->getRuntime(),
                'params' => $result->getParams(),
                'accuracy' => $result->getAccuracy(),
                'user' => $result->getUser()->getUsername(),
                'rank' => $rank
            )
        );
    }
}
