<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This plugin provides access to Moodle data in form of analytics and reports in real time.
 *
 *
 * @package    local_intelliboard
 * @copyright  2017 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @website    https://intelliboard.net/
 */

function clean_raw($value, $mode = true)
{
	$params = array("'","`");
	if($mode){
		$params[] = '"';
		$params[] = '(';
		$params[] = ')';
	}
	return str_replace($params, '', $value);
}

function intelliboard_clean($content){
	return trim($content);
}

function intelliboard_compl_sql($prefix = "", $sep = true)
{
    $completions = get_config('local_intelliboard', 'completions');
    $prefix = ($sep) ? " AND ".$prefix : $prefix;
    if (!empty($completions)) {
        return $prefix . "completionstate IN($completions)";
    } else {
        return $prefix . "completionstate IN(1,2)"; //Default completed and passed
    }
}
function intelliboard_grade_sql($avg = false, $params = null, $alias = 'g.', $round = 0, $alias_gi='gi.',$percent = false)
{
    global $CFG;
    require_once($CFG->dirroot . '/local/intelliboard/classes/grade_aggregation.php');

    $scales = get_config('local_intelliboard', 'scales');
    $raw = get_config('local_intelliboard', 'scale_raw');
    $total = get_config('local_intelliboard', 'scale_total');
    $value = get_config('local_intelliboard', 'scale_value');
    $percentage = get_config('local_intelliboard', 'scale_percentage');
    $scale_real = get_config('local_intelliboard', 'scale_real');

    if($percent){
        if ($avg) {
            return "ROUND(AVG(CASE WHEN ({$alias}rawgrademax-{$alias}rawgrademin) > 0 THEN (({$alias}finalgrade-{$alias}rawgrademin)/({$alias}rawgrademax-{$alias}rawgrademin))*100 ELSE {$alias}finalgrade END), {$round})";
        } else {
            return "ROUND((CASE WHEN ({$alias}rawgrademax-{$alias}rawgrademin) > 0 THEN (({$alias}finalgrade-{$alias}rawgrademin)/({$alias}rawgrademax-{$alias}rawgrademin))*100 ELSE {$alias}finalgrade END), {$round})";
        }
    }elseif ((isset($params->scale_raw) and $params->scale_raw) or ($raw and !isset($params->scale_raw))) {
        if((isset($params->scale_real) and $params->scale_real) or ($scale_real and !isset($params->scale_real))){
            if ($avg) {
                return local_intelliboard_grade_aggregation::get_real_grade_avg($alias, $round, $alias_gi);
            } else {
                return local_intelliboard_grade_aggregation::get_real_grade_single($alias, $round, $alias_gi);
            }
        }else{
            if ($avg) {
                return "ROUND(AVG({$alias}finalgrade), $round)";
            } else {
                return "ROUND({$alias}finalgrade, $round)";
            }
        }
    } elseif (isset($params->scales) and $params->scales) {
        $total = $params->scale_total;
        $value = $params->scale_value;
        $percentage = $params->scale_percentage;
        $scales = true;
    } elseif (isset($params->scales) and !$params->scales) {
        $scales = false;
    }

    if ($scales and $total and $value and $percentage) {
        $dif = $total - $value;
        if ($avg) {
            return "ROUND(AVG(CASE WHEN ({$alias}finalgrade - $value) < 0 THEN ((({$alias}finalgrade / $value) * 100) / 100) * $percentage ELSE ((((({$alias}finalgrade - $value) / $dif) * 100) / 100) * $percentage) + $percentage END), $round)";
        } else {
            return "ROUND((CASE WHEN ({$alias}finalgrade - $value) < 0 THEN ((({$alias}finalgrade / $value) * 100) / 100) * $percentage ELSE ((((({$alias}finalgrade - $value) / $dif) * 100) / 100) * $percentage) + $percentage END), $round)";
        }
    }
    if ($avg) {
        return "ROUND(AVG(CASE WHEN {$alias}rawgrademax > 0 THEN ({$alias}finalgrade/{$alias}rawgrademax)*100 ELSE {$alias}finalgrade END), $round)";
    } else {
        return "ROUND((CASE WHEN {$alias}rawgrademax > 0 THEN ({$alias}finalgrade/{$alias}rawgrademax)*100 ELSE {$alias}finalgrade END), $round)";
    }
}
function intelliboard_filter_in_sql($sequence, $column, $params = array(), $prfx = 0, $sep = true, $equal = true)
{
	global $DB;

	$sql = '';
	if($sequence){
		$items = explode(",", clean_param($sequence, PARAM_SEQUENCE));
		if(!empty($items)){
			$key = clean_param($column.$prfx, PARAM_ALPHANUM);
			list($sql, $sqp) = $DB->get_in_or_equal($items, SQL_PARAMS_NAMED, $key, $equal);
			$params = array_merge($params, $sqp);
			$sql = ($sep) ? " AND $column $sql ": " $column $sql ";
		}
	}
	return array($sql, $params);
}

function intelliboard_url()
{
    require('config.php');

    return $config['app_url'];
}
function intelliboard($params, $function = 'sso'){
	global $CFG;

    require('config.php');
	require_once($CFG->libdir . '/filelib.php');

    $api = get_config('local_intelliboard', 'api');
    $url = ($api) ? $config['api_url'] : $config['app_url'];

    $params['email'] = get_config('local_intelliboard', 'te1');
	$params['apikey'] = get_config('local_intelliboard', 'apikey');
    $params['url'] = $CFG->wwwroot;
	$params['lang'] = current_language();

	$curl = new curl;
	$json = $curl->post($url . 'moodleApi/' . $function, $params, []);

	$data = (object)json_decode($json);
	$data->status = (isset($data->status))?$data->status:'';
	$data->token = (isset($data->token))?$data->token:'';
    $data->reports = (isset($data->reports))?(array)$data->reports:null;
    $data->sets = (isset($data->reports))?(array)$data->sets:null;
	$data->alerts = (isset($data->alerts))?(array)$data->alerts:null;
	$data->alert = (isset($data->alert))?$data->alert:'';
    $data->data = (isset($data->data))? (array) $data->data : null;
	$data->shoppingcartkey = (isset($data->shoppingcartkey))? (array) $data->shoppingcartkey : null;

	return $data;
}
function chart_options(){
    $res = array();
    $res['CourseProgressCalculation'] = "{factor:'".md5("#FGS$%FGH245$".rand(0,1000))."',title:'',legend:{position:'none'},vAxis: {title:'Grade'},hAxis:{title:''},seriesType:'bars',series:{1:{type:'line'}},chartArea:{width:'92%',height: '76%',right:10,top:10},colors:['#1d7fb3', '#1db34f'],backgroundColor:{fill:'transparent'}}";

    $res['ActivityProgressCalculation'] = "{factor:'".md5("#FGS$%FGH245$".rand(0,1000))."',chartArea: {width: '95%',height: '76%',right:10,top:10},height: 250,hAxis: {format: 'dd MMM',gridlines: {},baselineColor: '#ccc',gridlineColor: '#ccc',},vAxis: {baselineColor: '#CCCCCC',gridlines: {count: 5,color: 'transparent',},minValue: 0},pointSize: 6,lineWidth: 2,colors: ['#1db34f', '#1d7fb3'],backgroundColor:{fill:'transparent'},tooltip: {isHtml: true},legend: { position: 'none' }}";

    $res['LearningProgressCalculation'] = "{factor:'".md5("#FGS$%FGH245$".rand(0,1000))."',legend:{position:'bottom',alignment:'center' },title: '',height: '350', pieHole: 0.4, pieSliceText:'value',chartArea:{width: '95%',height: '85%',right:10, top:10 },backgroundColor:{fill:'transparent'}}";

    $res['ActivityParticipationCalculation'] = "{factor:'".md5("#FGS$%FGH245$".rand(0,1000))."',legend:{ position:'bottom', alignment:'center' },title:'',height:'350',chartArea: {width: '85%',height: '85%',right:10,top:10 },backgroundColor:{fill:'transparent'}}";

    $res['CorrelationsCalculation'] = "{factor:'".md5("#FGS$%FGH245$".rand(0,1000))."',legend:'none',colors:['#1d7fb3', '#1db34f'],pointSize:16,tooltip:{isHtml: true},title:'',height:'350',chartArea:{ width: '85%', height:'70%',right:10, top:10 },backgroundColor:{fill:'transparent'},hAxis:{ticks: [], baselineColor: 'none', title:'------ Time Spent ----'},vAxis:{title:'Grade'}}";

    $res['CourseSuccessCalculation'] = "{factor:'".md5("#FGS$%FGH245$".rand(0,1000))."',legend:{ position:'bottom',alignment:'center' },title: '',height:'350',chartArea:{width:'95%',height: '85%',right:10,top:10},backgroundColor:{fill:'transparent'}}";

    $res['GradesCalculation'] = "{factor:'".md5("#FGS$%FGH245$".rand(0,1000))."',animate:true,diameter:40,guage:1,coverBg: '#fff',bgColor:'#efefef',fillColor:'#5c93c8',percentSize:'11px',percentWeight:'normal'}";

    $res['GradesFCalculation'] = "{factor:'".md5("#FGS$%FGH245$".rand(0,1000))."',animate:true,diameter:80,guage:2,coverBg:'#fff',bgColor:'#efefef',fillColor:'#5c93c8',percentSize:'15px',percentWeight:'normal'}";

    $res['GradesXCalculation'] = "{factor:'".md5("#FGS$%FGH245$".rand(0,1000))."',animate:true,diameter:40,guage:1,coverBg:'#fff',bgColor:'#efefef',fillColor:'#5c93c8',percentSize:'11px',percentWeight:'normal'}";

    $res['GradesZCalculation'] = "{factor:'".md5("#FGS$%FGH245$".rand(0,1000))."',animate:true,diameter:80,guage:2,coverBg:'#fff',bgColor:'#efefef',fillColor:'#5c93c8',percentSize:'15px',percentWeight:'normal'}";

    $res['CoursesCalculation'] = "{factor:'".md5("#FGS$%FGH245$".rand(0,1000))."',chartArea:{width:'90%',height:'76%',right:20,top:10},height:200,hAxis:{format:'dd MMM',gridlines: {},baselineColor:'#ccc',gridlineColor:'#ccc',},vAxis:{baselineColor:'#CCCCCC',gridlines:{count:5,color:'transparent',},minValue:0},pointSize:6,lineWidth:2,colors:['#1db34f','#1d7fb3'],backgroundColor:{fill:'transparent'},tooltip:{isHtml:true},legend:{position:'bottom'}}";

    $res['GradeProgression'] = "{factor:'".md5("#FGS$%FGH245$".rand(0,1000))."',chartArea:{width:'90%',height:'70%',top:10},hAxis:{format:'dd MMM',gridlines: {},baselineColor:'#ccc',gridlineColor:'#ccc',},vAxis:{baselineColor:'#CCCCCC',gridlines:{count:5,color:'transparent',},minValue:0},pointSize:6,lineWidth:2,colors:['#1db34f','#1d7fb3'],backgroundColor:{fill:'transparent'},tooltip:{isHtml:true},legend:{position:'bottom'}}";

    return (object) $res;
}
function seconds_to_time($t,$f=':'){
	if($t < 0){
		return "00:00:00";
	}
	return sprintf("%02d%s%02d%s%02d", floor($t/3600), $f, ($t/60)%60, $f, $t%60);
}


function intelliboard_csv_quote($value) {
	return '"'.str_replace('"',"'",$value).'"';
}
function intelliboard_export_report($json, $itemname, $format = 'csv', $output_type = 1)
{

    $name =  clean_filename($itemname . '-' . gmdate("Y-m-d"));

	if($format == 'csv'){
		return intelliboard_export_csv($json, $name, $output_type);
	}elseif($format == 'xls'){
        return intelliboard_export_xls($json, $name, $output_type);
	}elseif($format == 'pdf'){
        return intelliboard_export_pdf($json, $name, $output_type);
	}else{
        return intelliboard_export_html($json, $name, $output_type);
	}
}

function intelliboard_export_html($json, $filename, $type = 1)
{
    $html = '<h2>'.$filename.'</h2>';
    $html .= '<table width="100%">';
    $html .= '<tr>';
    foreach ($json->header as $col) {
        $html .= '<th>'. $col->name.'</th>';
    }
    $html .= '</tr>';
    foreach ($json->body as $row) {
    	$html .= '<tr>';
        foreach($row as $col) {
        	$value = str_replace('"', '', $col);
			$value = strip_tags($value);
            $html .= '<td>'. $value.'</td>';
        }
    	$html .= '</tr>';
    }
    $html .= '</table>';
    $html .= '<style>table{border-collapse: collapse; width: 100%;} table tr th {font-weight: bold;} table th, table td {border:1px solid #aaaaaa; padding: 7px 10px; font: 13px/13px Arial;} table tr:nth-child(odd) td {background-color: #f5f5f5;}</style>';

    switch ($type) {
        case 2:
            return $html;
        default:
            die($html);
    }
}
function intelliboard_export_csv($json, $filename, $type = 1)
{
	global $CFG;
    require_once($CFG->libdir . '/csvlib.class.php');

    $data = array(); $line = 0;
	foreach($json->header as $col){
		$value = str_replace('"', '', $col->name);
		$value = strip_tags($value);
		$data[$line][] = intelliboard_csv_quote($value);
	}
	$line++;
	foreach($json->body as $row){
		foreach($row as $col){
			$value = str_replace('"', '', $col);
			$value = strip_tags($value);
			$data[$line][] = intelliboard_csv_quote($value);
		}
		$line++;
	}
    $delimiters = array('comma'=>',', 'semicolon'=>';', 'colon'=>':', 'tab'=>'\\t');

    switch ($type) {
        case 1:
            return csv_export_writer::download_array($filename, $data, $delimiters['tab']);
        case 2:
            return csv_export_writer::print_array($data, $delimiters['tab'], '"', true);
        default:
            return csv_export_writer::print_array($data, $delimiters['tab']);
    }
}

function intelliboard_export_xls($json, $filename, $type = 1)
{
    global $CFG;
    require_once("$CFG->libdir/excellib.class.php");

    $filename .= '.xls';
    $filearg = '-';
    $workbook = new MoodleExcelWorkbook($filearg);
    $workbook->send($filename);
    $worksheet = array();
    $worksheet[0] = $workbook->add_worksheet('');
    $rowno = 0; $colno = 0;
    foreach ($json->header as $col) {
        $worksheet[0]->write($rowno, $colno, $col->name);
        $colno++;
    }
    $rowno++;
    foreach ($json->body as $row) {
        $colno = 0;
        foreach($row as $col) {
        	$value = str_replace('"', '', $col);
			$value = strip_tags($value);
            $worksheet[0]->write($rowno, $colno, $value);
            $colno++;
        }
        $rowno++;
    }

    switch ($type) {
        case 1:
            $workbook->close();
            exit;
        case 2:
            ob_start();
            $workbook->close();
            $data = ob_get_contents();
            ob_end_clean();
            return $data;
        default:
            return $workbook->close();
    }

}
function intelliboard_export_pdf($json, $name, $type = 1)
{
    global $CFG, $SITE;

	require_once($CFG->libdir . '/pdflib.php');

    $fontfamily = PDF_FONT_NAME_MAIN;

    $doc = new pdf();
    $doc->SetTitle($name);
    $doc->SetAuthor('Moodle ' . $CFG->release);
    $doc->SetCreator('local/intelliboard/reports.php');
    $doc->SetKeywords($name);
    $doc->SetSubject($name);
    $doc->SetMargins(15, 30);
    $doc->setPrintHeader(true);
    $doc->setHeaderMargin(10);
    $doc->setHeaderFont(array($fontfamily, 'b', 10));
    $doc->setHeaderData('', 0, $SITE->fullname, $name);
    $doc->setPrintFooter(true);
    $doc->setFooterMargin(10);
    $doc->setFooterFont(array($fontfamily, '', 8));
    $doc->AddPage();
    $doc->SetFont($fontfamily, '', 8);
    $doc->SetTextColor(0,0,0);
    $name .= '.pdf';
    $html = '<table width="100%">';
    $html .= '<tr>';
    foreach ($json->header as $col) {
        $html .= '<th>'. $col->name.'</th>';
    }
    $html .= '</tr>';
    foreach ($json->body as $row) {
    	$html .= '<tr>';
        foreach($row as $col) {
        	$value = str_replace('"', '', $col);
			$value = strip_tags($value);
            $html .= '<td>'. $value.'</td>';
        }
    	$html .= '</tr>';
    }
    $html .= '</table>';
    $html .= '<style>';
    $html .= 'td{border:0.1px solid #000; padding:10px;}';
    $html .= '</style>';
    $doc->writeHTML($html);

    switch ($type) {
        case 1:
            $doc->Output($name);
            die();
        case 2:
            return $doc->Output($name, 'S');
        default:
            die($doc->Output($name, 'S'));
    }


}
function get_modules_names() {
    global $DB;

    $modules = $DB->get_records_sql("SELECT m.id, m.name FROM {modules} m WHERE m.visible = 1");
    $nameColumn = array_reduce($modules, function($carry, $module) {
        return $carry . " WHEN m.name='{$module->name}' THEN (SELECT name FROM {".$module->name."} WHERE id = cm.instance)";
    }, '');

    return $nameColumn?  "CASE $nameColumn ELSE 'NONE' END" : "''";
}

function exclude_not_owners($columns) {

    global $DB;
    $owners_users = array();
    $owners_courses = array();
    $owners_cohorts = array();

    foreach ($columns as $type => $value) {
        if ($type == "users") {
            $owners_users = array_merge($owners_users, $DB->get_fieldset_sql(" SELECT userid FROM {local_intelliboard_assign} WHERE type = 'users' AND instance = :userid", array('userid' => $value)));

            $owners_users = array_merge($owners_users, $DB->get_fieldset_sql("SELECT lia.userid FROM {local_intelliboard_assign} lia
              INNER JOIN {context} ctx ON lia.type = 'courses' AND ctx.instanceid = lia.instance AND ctx.contextlevel = 50
              INNER JOIN {role_assignments} ra ON ctx.id = ra.contextid
              WHERE ra.userid = ?
            ", array('userid' => $value)));
            $owners_users = array_merge($owners_users, $DB->get_fieldset_sql("SELECT lia.userid FROM {local_intelliboard_assign}  lia
              INNER JOIN {cohort_members} cm ON lia.type = 'cohorts' AND cm.cohortid = lia.instance
              WHERE cm.userid = ?
            ", array('userid' => $value)));

        } elseif ($type == 'courses') {
            $owners_courses = array_merge($owners_courses, $DB->get_fieldset_sql(" SELECT userid FROM {local_intelliboard_assign} WHERE type = 'courses' AND instance = :courseid", array('courseid' => $value)));
        } elseif ($type == 'cohorts') {
            $owners_cohorts = array_merge($owners_cohorts, $DB->get_fieldset_sql(" SELECT userid FROM {local_intelliboard_assign} WHERE type = 'cohorts' AND instance = :cohortid", array('cohortid' => $value)));
        }
    }

    $owners = array_merge($owners_users, $owners_courses, $owners_cohorts);
    $sql = "SELECT userid FROM {local_intelliboard_assign}";

    if ($owners) {
        $sql .= " WHERE userid NOT IN (" . implode(",", $owners) . ")";
    }

    return $DB->get_fieldset_sql($sql);

}
