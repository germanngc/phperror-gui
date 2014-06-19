<?php

/**
 * PHP Error Log GUI
 * 
 * A simple but effective single-file GUI for viewing the PHP error log.
 * 
 * @author Andrew Collington, andy@amnuts.com
 * @license MIT, http://acollington.mit-license.org/
 */

/**
 * @var string|null Path to error log file or null to get from ini settings
 */
$error_log = null;
/**
 * @var string|null Path to log cache - must be writable - null for no cache
 */
$cache     = null;

/**
 * https://gist.github.com/amnuts/8633684
 */
function osort(&$array, $properties)
{
    if (is_string($properties)) {
        $properties = array($properties => SORT_ASC);
    }
    uasort($array, function($a, $b) use ($properties) {
        foreach($properties as $k => $v) {
            if (is_int($k)) {
                $k = $v;
                $v = SORT_ASC;
            }
            $collapse = function($node, $props) {
                if (is_array($props)) {
                    foreach ($props as $prop) {
                        $node = (!isset($node->$prop)) ? null : $node->$prop;
                    }
                    return $node;
                } else {
                    return (!isset($node->$props)) ? null : $node->$props;
                }
            };
            $aProp = $collapse($a, $k);
            $bProp = $collapse($b, $k);
            if ($aProp != $bProp) {
                return ($v == SORT_ASC)
                ? strnatcasecmp($aProp, $bProp)
                : strnatcasecmp($bProp, $aProp);
            }
        }
        return 0;
    });
}

if ($error_log === null) {
    $error_log = ini_get('error_log');
}

if (empty($error_log)) {
    die('No error log was defined or could be determined from the ini settings.');
}

try {
	$log = new SplFileObject($error_log);
	$log->setFlags(SplFileObject::DROP_NEW_LINE);
} catch (RuntimeException $e) {
	die("The file '{$error_log}' cannot be opened for reading.\n");
}

if ($cache !== null && file_exists($cache)) {
	$cacheData = unserialize(file_get_contents($cache));
	extract($cacheData);
	$log->fseek($seek);
}

$prevError = new stdClass;
while (!$log->eof()) {
	if (preg_match('/stack trace:$/i', $log->current())) {
		$stackTrace = $parts = [];
		$log->next();
		while ((preg_match('!^\[(?P<time>[^\]]*)\] PHP\s+(?P<msg>\d+\. .*)$!', $log->current(), $parts)
			|| preg_match('!^(?P<msg>#\d+ .*)$!', $log->current(), $parts)
			&& !$log->eof())
		) {
			$stackTrace[] = $parts['msg'];
			$log->next();
		}
		if (substr($stackTrace[0], 0, 2) == '#0') {
			$stackTrace[] = $log->current();
			$log->next();
		}
		$prevError->trace = join("\n", $stackTrace);
	}
	
	$more = [];
	while (!preg_match('!^\[(?P<time>[^\]]*)\] PHP (?P<type>.*?):\s+(?P<msg>.*)$!', $log->current()) && !$log->eof()) {
		$more[] = $log->current();
		$log->next();
	}
	if (!empty($more)) {
		$prevError->more = join("\n", $more);
	}
	
	$parts = [];
	if (preg_match('!^\[(?P<time>[^\]]*)\] PHP (?P<type>.*?):\s+(?P<msg>.*)$!', $log->current(), $parts)) {
		$msg = trim($parts['msg']);
		$type = strtolower(trim($parts['type']));
		$types[$type] = strtolower(preg_replace('/[^a-z]/i', '', $type));
		if (!isset($logs[$msg])) {
			$data = [
				'type'  => $type,
				'first' => date_timestamp_get(date_create($parts['time'])),
				'last'  => date_timestamp_get(date_create($parts['time'])),
				'msg'   => $msg,
				'hits'  => 1,
				'trace' => null,
				'more'  => null
			];
			$subparts = [];
			if (preg_match('!(?<core> in (?P<path>(/|zend)[^ :]*)(?: on line |:)(?P<line>\d+))$!', $msg, $subparts)) {
				$data['path'] = $subparts['path'];
				$data['line'] = $subparts['line'];
				$data['core'] = str_replace($subparts['core'], '', $data['msg']);
				$data['code'] = '';
				try {
					$file = new SplFileObject(str_replace('zend.view://', '', $subparts['path']));
					$file->seek($subparts['line'] - 4);
					$i = 7;
					do {
						$data['code'] .= $file->current();
						$file->next();
					} while (--$i && !$file->eof());
				} catch (Exception $e) {}
			}
			$logs[$msg] = (object)$data;
			if (!isset($typecount[$type])) {
				$typecount[$type] = 1;
			} else {
				++$typecount[$type];
			}
		} else {
			++$logs[$msg]->hits;
			$time = date_timestamp_get(date_create($parts['time']));
			if ($time < $logs[$msg]->first) {
				$logs[$msg]->first = $time;
			}
			if ($time > $logs[$msg]->last) {
				$logs[$msg]->last = $time;
			}
		}
		$prevError = &$logs[$msg];
	}
	$log->next();
}

if ($cache !== null) {
	$cacheData = serialize(['seek' => $log->getSize(), 'logs' => $logs, 'types' => $types, 'typecount' => $typecount]);
	file_put_contents($cache, $cacheData);
}

$log = null;

osort($logs, ['last' => SORT_DESC]);
$total = count($logs);
ksort($types);

?><!doctype html>
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="cleartype" content="on">
    <meta name="HandheldFriendly" content="True">
    <meta name="MobileOptimized" content="320">
    <title>PHP error log viewer</title>
    <script src="https://code.jquery.com/jquery-2.1.1.min.js" type="text/javascript"></script>
    <style type="text/css">
        body { font-family: Arial, Helvetica, sans-serif; font-size: 80%; padding: 2em; }
        article { width: 100%; display: block; margin: 0 0 1em 0; }
        article > div { border: 1px solid #000000; border-left-width: 10px; padding: 1em; }
        article > div > b { font-weight: bold; display: block; }
        article > div > i { display: block; }
        article > div > blockquote { 
            display: none;
            background-color: #ededed;
            border: 1px solid #ababab;
            padding: 1em;
            overflow: auto;
            margin: 0;
        }
        #typeFilter, #pathFilter, #sortOptions { border: 0; margin: 0; padding: 0; }
        #pathFilter input { width: 30em; }
        #typeFilter label { border-bottom: 2px solid #000000; margin-right: 1em; }
        #nothingToShow { display: none; }
        .odd { background-color: #fcfcfc; }
        .even { background-color: #f8f8f8; }
        .deprecated { border-color: #acacac !important; }
        .notice { border-color: #6dcff6 !important; }
        .warning { border-color: #fbaf5d !important; }
        .fatalerror { border-color: #f26c4f !important; }
        .strictstandards { border-color: #534741 !important; }
        .catchablefatalerror { border-color: #f68e56 !important; }
        .parseerror { border-color: #aa66cc !important; }
    </style>
</head>
<body>

<?php if (!empty($logs)): ?>

    <fieldset id="typeFilter">
        <p>Filter by type: 
            <?php foreach ($types as $title => $class): ?>
            <label class="<?php echo $class; ?>"><input type="checkbox" value="<?php echo $class; ?>" checked="checked" /> <?php echo $title; ?> (<?php echo $typecount[$title]; ?>)</label>
            <?php endforeach; ?>
        </p>
    </fieldset>

    <fieldset id="pathFilter">
        <p><label>Filter by path: <input type="text" value="" placeholder="Just start typing..." /></label></p>
    </fieldset>

    <fieldset id="sortOptions">
        <p>Sort by: <a href="?type=last&amp;order=asc">last seen (<span>asc</span>)</a>, <a href="?type=hits&amp;order=desc">hits (<span>desc</span>)</a>, <a href="?type=type&amp;order=asc">type (<span>a-z</span>)</a></p>
    </fieldset>
    
    <p id="entryCount"><?php echo $total; ?> distinct entr<?php echo ($total == 1 ? 'y' : 'ies'); ?></p>

    <section>
    <?php foreach($logs as $log): ?>
        <article class="<?php echo $types[$log->type]; ?>" 
                data-path="<?php if (!empty($log->path)) echo htmlentities($log->path); ?>"
                data-line="<?php if (!empty($log->line)) echo $log->line; ?>"
                data-type="<?php echo $types[$log->type]; ?>"
                data-hits="<?php echo $log->hits; ?>"
                data-last="<?php echo $log->last; ?>">
            <div class="<?php echo $types[$log->type]; ?>">
                <i><?php echo htmlentities($log->type); ?></i> <b><?php echo htmlentities((empty($log->core) ? $log->msg : $log->core)); ?></b><br />
                <?php if (!empty($log->more)): ?>
                	<p><i><?php echo nl2br(htmlentities($log->more)); ?></i></p>
                <?php endif; ?>
                <p>
                    <?php if (!empty($log->path)): ?>
                        <?php echo htmlentities($log->path); ?>, line <?php echo $log->line; ?><br />
                    <?php endif; ?>
                    last seen <?php echo date_format(date_create("@{$log->last}"), 'Y-m-d G:ia'); ?>, <?php echo $log->hits; ?> hit<?php echo ($log->hits == 1 ? '' : 's'); ?><br />
                </p>
                <?php if (!empty($log->trace)): ?>
                    <?php $uid = uniqid('tbq'); ?>
                    <p><a href="#" class="traceblock" data-for="<?php echo $uid; ?>">Show stack trace</a></p>
                    <blockquote id="<?php echo $uid; ?>"><?php echo highlight_string($log->trace, true); ?></blockquote>
                <?php endif; ?>
                <?php if (!empty($log->code)): ?>
                    <?php $uid = uniqid('cbq'); ?>
                    <p><a href="#" class="codeblock" data-for="<?php echo $uid; ?>">Show code snippet</a></p>
                    <blockquote id="<?php echo $uid; ?>"><?php echo highlight_string($log->code, true); ?></blockquote>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
    </section>
    
    <p id="nothingToShow">Nothing to show with your selected filtering.</p>
<?php else: ?>
    <p>There are currently no PHP error log entries available.</p>
<?php endif; ?>

<script type="text/javascript">
function parseQueryString(qs) {
    var query = (qs || '?').substr(1), map = {};
    query.replace(/([^&=]+)=?([^&]*)(?:&+|$)/g, function(match, key, value) {
        (map[key] = map[key] || value);
    });
    return map;
}

function stripe() {
    $('article:visible:odd').removeClass('even').addClass('odd');
    $('article:visible:even').removeClass('odd').addClass('even');
}

function visible() {
    var len = $('article:visible').length;
    if (len == 0) {
        $('#nothingToShow').show();
        $('#entryCount').text('0 entries showing (<?php echo $total; ?> filtered out)');
    } else {
        $('#nothingToShow').hide();
        if (len == <?php echo $total; ?>) {
            $('#entryCount').text('<?php echo $total; ?> distinct entr<?php echo ($total == 1 ? 'y' : 'ies'); ?>');
        } else {
            $('#entryCount').text(len + ' distinct entr' + (len == 1 ? 'y' : 'ies') + ' showing ('
                + (<?php echo $total; ?> - len) + ' filtered out)');
        }
    }
    stripe();
}

function filterSet() {
    var checked = $('#typeFilter input:checkbox:checked').map(function(){
        return $(this).val();
    }).get();
    var input = $('#pathFilter input').val();
    $('article').each(function(){
        var a = $(this);
        if ((input.length && a.data('path').toLowerCase().indexOf(input.toLowerCase()) == -1)
                || (jQuery.inArray(a.data('type'), checked) == -1)
        ) {
            a.css('display', 'none');
        } else {
            a.css('display', 'block');
        }
    });
}

function sortEntries(type, order) {
    var aList = $('article');
    aList.sort(function(a, b){
        if (!isNaN($(a).data(type))) {
            var entryA = parseInt($(a).data(type));
            var entryB = parseInt($(b).data(type));
        } else {
            var entryA = $(a).data(type);
            var entryB = $(b).data(type);
        }
        if (order == 'asc') {
            return (entryA < entryB) ? -1 : (entryA > entryB) ? 1 : 0;
        }
        return  (entryB < entryA) ? -1 : (entryB > entryA) ? 1 : 0;
    });
    $('section').html(aList);
}

$(function(){
    $('#typeFilter input:checkbox').on('change', function(){
        filterSet();
        visible();
    });
    $('#pathFilter input').on('keyup', function(){
        filterSet();
        visible();
    });
    $('#sortOptions a').on('click', function(){
        var qs = parseQueryString($(this).attr('href'));
        sortEntries(qs.type, qs.order);
        $(this).attr('href', '?type=' + qs.type + '&order=' + (qs.order == 'asc' ? 'desc' : 'asc'));
        if (qs.type == 'type') {
            $('span', $(this)).text((qs.order == 'asc' ? 'z-a' : 'a-z'));
        } else {
            $('span', $(this)).text((qs.order == 'asc' ? 'desc' : 'asc'));
        }
        return false;
    });
    $(document).on('click', 'a.codeblock, a.traceblock', function(e){
        $('#' + $(this).data('for')).toggle();
        return false;
    });
    stripe();
});
</script>

</body>
</html>