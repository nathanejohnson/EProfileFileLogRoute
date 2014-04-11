#Yii extension for logging profiling data to a file.

This is mostly a copy and paste of CProfileLogRoute, but instead of extending CWebRoute, it extends CFileLogRoute.

Logs time / date stamps with millisecond precision.

##Requirements

Yii 1.1 or above

##Usage
Drop this class into your extensions directory, make sure that it's imported either explicitly 

    'import' => array('extensions.EProfileFileLogRoute') 

or implicitly

    'import' => array('extensions.*')

Here is an example configuration:

        'log' => array(
            'class' => 'CLogRouter',
            'routes' => array(
                array(
                    'class'=>'EProfileFileLogRoute',
                    'categories' => 'application',
                    'logFile' => 'profile.log',
                    'groupByToken' => 'true',
                    'report' => 'summary'
                ),
            ),
        ),

