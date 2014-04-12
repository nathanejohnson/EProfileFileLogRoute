<?php
/**
 * EProfileFileLogRoute class file.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>,
 *  customized by Nathan Johnson <nathan@nathanjohnson.info to support file logging
 * @link http://www.yiiframework.com/
 * @copyright Copyright &copy; 2008-2011 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

/**
 * EProfileFileLogRoute logs the profiling result to a log file.
 *
 * The profiling is done by calling {@link YiiBase::beginProfile()} and {@link
 * YiiBase::endProfile()},
 * which marks the begin and end of a code block.
 *
 * EProfileFileLogRoute supports two types of report by setting the {@link
 * setReport report} property:
 * <ul>
 * <li>summary: list the execution time of every marked code block</li>
 * <li>callstack: list the mark code blocks in a hierarchical view reflecting
 * their calling sequence.</li>
 * </ul>
 *
 * This class was mostly copied and pasted from the CProfileLogRoute and modified to
 * extend CFileLogRoute instead of CWebRoute.
 *
 * @property string $report The type of the profiling report to display.
 *           Defaults to 'summary'.
 *
 */
class EProfileFileLogRoute extends CFileLogRoute {
    /**
     *
     * @var boolean whether to aggregate results according to profiling tokens.
     *      If false, the results will be aggregated by categories.
     *      Defaults to true. Note that this property only affects the summary
     *      report
     *      that is enabled when {@link report} is 'summary'.
     */
    public $groupByToken = true;
    /**
     *
     * @var string type of profiling report to display
     */
    private $_report = 'summary';

    /**
     * Initializes the route.
     * This method is invoked after the route is created by the route manager.
     */
    public function init() {
        $this->levels = CLogger::LEVEL_PROFILE;
        parent::init();
    }

    /**
     *
     * @return string the type of the profiling report to display. Defaults to
     *         'summary'.
     */
    public function getReport() {
        return $this->_report;
    }

    /**
     *
     * @param string $value
     *            the type of the profiling report to display. Valid values
     *            include 'summary' and 'callstack'.
     */
    public function setReport($value) {
        if ($value === 'summary' || $value === 'callstack')
            $this->_report = $value;
        else
            throw new CException(
                    Yii::t( 'yii',
                            'EProfileFileLogRoute.report "{report}" is invalid. Valid values include "summary" and "callstack".',
                            array (
                                '{report}' => $value
                            ) ) );
    }

    /**
     * cribbed from http://anton.logvinenko.name/en/blog/microseconds-in-yii-framework-application-log.html
     * @see CLogRoute::formatLogMessage()
     */
    protected function formatLogMessage($message, $level, $category, $time) {
        $micro = sprintf( "%06d", ($time - floor( $time )) * 1000000 );
        $returnString = date( 'Y-m-d H:i:s', $time ) .
                 ".$micro [$level] [$category] $message\n";
        return $returnString;
    }

    /**
     * logs the log messages to a file.
     *
     * @param array $logs
     *            list of log messages
     */
    public function processLogs($logs) {
        $app = Yii::app();
        $newLogs = array ();

        if ($this->getReport() === 'summary') {
            $processedEntries = $this->createSummary( $logs );

            foreach ( $processedEntries as $entry ) {
                list ( $token, $calls, $min, $max, $total, $category ) = $entry;
                $message = sprintf(
                        '%s was called %d times.  min execution time was %0.5f.  max time was %0.5f.  total time spent was %0.5f',
                        $token, $calls, $min, $max, $total );
                $level = CLogger::LEVEL_PROFILE;

                $time = microtime( true );
                $newLogs[] = array (
                    $message,
                    $level,
                    $category,
                    $time
                );
            }
        }
        else {
            $processedEntries = $this->createCallstack( $logs );
            foreach ( $processedEntries as $entry ) {
                list ( $token, $time, $depth, $category ) = $entry;
                $spaces = str_repeat( ' ', $depth * 4 );
                $message = sprintf( '%s%s was called and took %.5f seconds',
                        $spaces, $token, $time );
                $level = CLogger::LEVEL_PROFILE;
                $now = microtime( true );
                $newLogs[] = array (
                    $message,
                    $level,
                    $category,
                    $now
                );
            }
        }
        parent::processLogs( $newLogs );
    }

    /**
     * Returns the callstack of the profiling procedures for display.
     *
     * @param array $logs
     *            list of logs

     * @return array
     *             call stack information, 2d array
     *             (token, time, callcount, category)
     */
    protected function createCallstack($logs) {
        $stack = array ();
        $results = array ();
        $n = 0;
        foreach ( $logs as $log ) {
            if ($log[1] !== CLogger::LEVEL_PROFILE)
                continue;
            $message = $log[0];
            if (!strncasecmp( $message, 'begin:', 6 )) {
                $log[0] = substr( $message, 6 );
                $log[4] = $n;
                $stack[] = $log;
                $n ++;
            }
            elseif (!strncasecmp( $message, 'end:', 4 )) {
                $token = substr( $message, 4 );
                if (($last = array_pop( $stack )) !== null && $last[0] === $token) {
                    $delta = $log[3] - $last[3];
                    $results[$last[4]] = array (
                        $token,
                        $delta,
                        count( $stack ),
                        $last[2]
                    );
                }
                else
                    throw new CException(
                            Yii::t( 'yii',
                                    'EProfileFileLogRoute found a mismatching code block "{token}". Make sure the calls to Yii::beginProfile() and Yii::endProfile() be properly nested.',
                                    array (
                                        '{token}' => $token
                                    ) ) );
            }
        }
        // remaining entries should be closed here
        $now = microtime( true );
        while ( ($last = array_pop( $stack )) !== null )
            $results[$last[4]] = array (
                $last[0],
                $now - $last[3],
                count( $stack ),
                $last[2]
            );
        ksort( $results );
        return $results;
    }

    /**
     * Returns the summary info of the profiling result.
     *
     * @param array $logs
     *            list of logs
     * @return array
     *            profiling information, 2d array
     *            (token, callcount, min, max, total and category)
     */
    protected function createSummary($logs) {
        $stack = array ();
        foreach ( $logs as $log ) {
            if ($log[1] !== CLogger::LEVEL_PROFILE)
                continue;
            $message = $log[0];
            if (!strncasecmp( $message, 'begin:', 6 )) {
                $log[0] = substr( $message, 6 );
                $stack[] = $log;
            }
            elseif (!strncasecmp( $message, 'end:', 4 )) {
                $token = substr( $message, 4 );
                if (($last = array_pop( $stack )) !== null && $last[0] === $token) {
                    $delta = $log[3] - $last[3];
                    if (!$this->groupByToken)
                        $token = $log[2];
                    if (isset( $results[$token] ))
                        $results[$token] = $this->aggregateResult(
                                $results[$token], $delta );
                    else
                        $results[$token] = array (
                            $token,
                            1,
                            $delta,
                            $delta,
                            $delta,
                            $last[2]
                        );
                }
                else
                    throw new CException(
                            Yii::t( 'yii',
                                    'EProfileFileLogRoute found a mismatching code block "{token}". Make sure the calls to Yii::beginProfile() and Yii::endProfile() be properly nested.',
                                    array (
                                        '{token}' => $token
                                    ) ) );
            }
        }

        $now = microtime( true );
        while ( ($last = array_pop( $stack )) !== null ) {
            $delta = $now - $last[3];
            $token = $this->groupByToken ? $last[0] : $last[2];
            if (isset( $results[$token] ))
                $results[$token] = $this->aggregateResult( $results[$token],
                        $delta );
            else
                $results[$token] = array (
                    $token,
                    1,
                    $delta,
                    $delta,
                    $delta,
                    $last[2]
                );
        }

        $entries = array_values( $results );
        $func = create_function( '$a,$b', 'return $a[4]<$b[4]?1:0;' );
        usort( $entries, $func );

        return $entries;
    }

    /**
     * Aggregates the report result.
     *
     * @param array $result
     *            log result for this code block
     * @param float $delta
     *            time spent for this code block
     * @return array
     */
    protected function aggregateResult($result, $delta) {
        list ( $token, $calls, $min, $max, $total, $category ) = $result;
        if ($delta < $min)
            $min = $delta;
        elseif ($delta > $max)
            $max = $delta;
        $calls ++;
        $total += $delta;
        return array (
            $token,
            $calls,
            $min,
            $max,
            $total
        );
    }
}