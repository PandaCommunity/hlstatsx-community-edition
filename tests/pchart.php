<?php
// Run from the repository root: php tests/pchart.php
// Every diagnostic is a failure, including PHP 8.5 GD deprecations.
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require __DIR__ . '/../web/includes/pChart/pData.class';
require __DIR__ . '/../web/includes/pChart/pChart.class';
require __DIR__ . '/../web/includes/pChart/pCache.class';
require __DIR__ . '/../web/includes/functions_graph.php';

// These public methods deliver a response and exit, so test them in a child PHP.
if (($argv[1] ?? '') === '--cache') {
    (new pCache($argv[2] . '/'))->GetFromCache('test', [['value' => 3]]);
    exit(1);
}
if (($argv[1] ?? '') === '--map') {
    $chart = new pChart(380, 200);
    $chart->tmpFolder = $argv[2] . '/';
    $chart->getImageMap('map?x=1?y=2');
    exit(1);
}
if (($argv[1] ?? '') === '--heatmap') {
    require __DIR__ . '/../heatmaps/heatmap.class.php';
    echo 'heatmap loaded';
    exit(0);
}

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$failures = 0;
function runCase($name, $test)
{
    global $failures;
    try {
        $test();
        echo "PASS $name\n";
    } catch (Throwable $error) {
        $failures++;
        echo "FAIL $name: {$error->getMessage()} ({$error->getFile()}:{$error->getLine()})\n";
    }
}

runCase('scalar, single, multiple and repeated AddPoint', function () {
    $data = new pData();
    $data->AddPoint(42);
    $data->AddPoint('43');
    $data->AddPoint([44]);
    $data->AddPoint([45, 46]);
    $data->AddPoint([7, 8, 9, 10, 11], 'other');
    $data->AddPoint([], 'Serie1');
    check(array_column($data->GetData(), 'Serie1') === [42, '43', 44, 45, 46], 'points must append');
    check(array_column($data->GetData(), 'other') === [7, 8, 9, 10, 11], 'series must align');
});

runCase('append preserves null observations and aligned labels', function () {
    $data = new pData();
    $data->AddPoint([1, null]);
    $data->AddPoint(3);
    $data->AddPoint(null);
    $data->AddPoint([5, 6]);
    $data->AddPoint(['one', 'gap', 'three', 'gap again', 'five', 'six'], 'dates');
    check(array_column($data->GetData(), 'Serie1') === [1, null, 3, null, 5, 6], 'null points occupy series slots');
    check($data->GetData()[4]['dates'] === 'five', 'date labels must remain aligned');
});

runCase('heatmap loads without PHP 8.5 diagnostics', function () {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --heatmap 2>&1', $output, $status);
    check($status === 0 && implode("\n", $output) === 'heatmap loaded', implode("\n", $output));
});

$tmp = sys_get_temp_dir() . '/hlstats-pchart-' . getmypid();
mkdir($tmp);
try {
    runCase('empty warnings render', function () use ($tmp) {
        $chart = new pChart(380, 200);
        $chart->reportWarnings();
        $chart->Render($tmp . '/warnings.png');
        check(getimagesize($tmp . '/warnings.png')[0] === 380, 'PNG width');
    });

    runCase('CSV and palette regex delimiters', function () use ($tmp) {
        file_put_contents($tmp . '/data.csv', "first; second\n12; 34\n56; 78\n");
        $data = new pData();
        $data->ImportFromCSV($tmp . '/data.csv', ';[[:space:]]*', -1, true);
        check(array_column($data->GetData(), 'Serie2') === [34, 78], 'regex CSV delimiter');
        check($data->GetDataDescription()['Description']['Serie2'] === 'second', 'CSV headers');
        file_put_contents($tmp . '/palette', "12; 34; 56\n78; 90; 123\n");
        $chart = new pChart(380, 200);
        $chart->loadColorPalette($tmp . '/palette', ';[[:space:]]*');
        check($chart->Palette[1] === ['R' => '78', 'G' => '90', 'B' => '123'], 'regex palette delimiter');
    });

    runCase('empty and invalid scale is a nonfatal no-op', function () use ($tmp) {
        $data = new pData();
        $data->AddSerie();
        foreach ([[], null, [42], [[]], [['Serie1' => 'bad']], [['Serie1' => INF]], [['Name' => 'label']]] as $rows) {
            $chart = new pChart(380, 200);
            $chart->drawBackground(90, 90, 90);
            check($chart->drawScale($rows, $data->GetDataDescription(), SCALE_NORMAL, 0, 0, 0) === -1, 'invalid scale must be rejected');
            $chart->Render($tmp . '/invalid.png');
            $image = imagecreatefrompng($tmp . '/invalid.png');
            check(imagecolorat($image, 100, 100) === 0x5a5a5a, 'invalid data must not draw');
        }
    });

    runCase('pCache GdImage write/read/delete', function () use ($tmp) {
        $cache = new pCache($tmp . '/');
        $chart = new pChart(380, 200);
        $chart->drawBackground(12, 34, 56);
        $data = [['value' => 3]];
        $cache->WriteToCache('test', $data, $chart);
        check($cache->IsInCache('test', $data), 'cache write');
        $png = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --cache ' . escapeshellarg($tmp));
        $image = imagecreatefromstring($png);
        check(imagesx($image) === 380 && imagesy($image) === 200 && imagecolorat($image, 0, 0) === 0x0c2238, 'cache read bytes');
        $cache->DeleteFromCache('test', $data);
        check(!$cache->IsInCache('test', $data), 'cache delete');
    });

    $invalidRenderers = [
        'drawLineGraph', 'drawFilledLineGraph', 'drawCubicCurve', 'drawFilledCubicCurve',
        'drawPlotGraph', 'drawBarGraph', 'drawOverlayBarGraph', 'drawStackedBarGraph', 'drawLimitsGraph',
        'drawRadarAxis', 'drawRadar', 'drawFilledRadar', 'drawBasicPieGraph', 'drawFlatPieGraph', 'drawPieGraph',
        'drawFlatPieGraphWithShadow', 'drawXYScale', 'drawXYGraph', 'drawXYPlotGraph',
        'drawArea', 'drawPieLegend', 'setLabel', 'writeValues', 'drawRightScale',
    ];
    foreach ($invalidRenderers as $method) {
        runCase("empty/invalid $method is a nonfatal no-op", function () use ($tmp, $method) {
            $data = new pData();
            $data->AddSerie();
            $data->SetSerieName('A');
            $description = $data->GetDataDescription();
            foreach ([[], null, [42], [[]]] as $rows) {
                $chart = new pChart(380, 200);
                $chart->drawBackground(90, 90, 90);
                $chart->setGraphArea(50, 28, 339, 174);
                $chart->Render($tmp . '/before-invalid.png');
                $args = [$rows, $description];
                if (strpos($method, 'PieGraph') !== false) {
                    $args = [$rows, $description, 190, 100, 50];
                } elseif ($method === 'drawArea') {
                    $args = [$rows, 'Serie1', 'Serie2', 12, 34, 56];
                } elseif ($method === 'drawPieLegend') {
                    $args = [10, 10, $rows, $description, 12, 34, 56];
                } elseif ($method === 'setLabel') {
                    $args = [$rows, $description, 'Serie1', 1, 'Point'];
                } elseif ($method === 'writeValues') {
                    $args[] = ['Serie1'];
                } elseif (strpos($method, 'drawXY') === 0) {
                    $args = [$rows, $description, 'Serie1', 'Serie2'];
                    if ($method === 'drawXYScale') {
                        $args = array_merge($args, [0, 0, 0]);
                    }
                } elseif ($method === 'drawRightScale') {
                    $args = [$rows, $description, SCALE_NORMAL, 0, 0, 0];
                }
                check($chart->$method(...$args) === -1, 'invalid render must return -1');
                $chart->reportWarnings();
                ob_start();
                try {
                    $chart->Render($tmp . '/invalid-render.png');
                } finally {
                    $warnings = ob_get_clean();
                }
                check(strpos($warnings, '[Warning]') !== false, 'invalid render must report a warning');
                check(file_get_contents($tmp . '/invalid-render.png') === file_get_contents($tmp . '/before-invalid.png'), 'invalid render must leave all pixels unchanged');
            }
        });
    }

    runCase('legacy dashed lines truncate only at GD coordinates', function () {
        $image = imagecreatetruecolor(40, 40);
        image_dashed_line($image, 3.9, 7.9, 20.9, 7.9, [0xffffff, -1]);
        check(imagecolorat($image, 3, 7) === 0xffffff, 'fractional line starts at truncated pixel');
        check(imagecolorat($image, 3, 8) === 0, 'fractional line must not round up');
        image_dashed_line($image, 25.9, 4.9, 25.9, 21.9, [0xffffff, -1]);
        check(imagecolorat($image, 25, 4) === 0xffffff, 'vertical line uses the same truncation policy');
        check(imagecolorat($image, 26, 4) === 0, 'vertical line must not round up');
    });

    runCase('legacy graph fractional grids and zero baselines', function () use ($tmp) {
        global $max_pos_y, $legend_x, $bar_type, $deletedays, $server_load_type, $first_entry, $gray_border;
        foreach ([0, 1, 2] as $bar_type) {
            foreach ([0, 123.75, 9999.25, 10000.75] as $value) {
                foreach ([1, 2, 3, 4] as $server_load_type) {
                    $max_pos_y = [];
                    $legend_x = 0;
                    $deletedays = 28;
                    $first_entry = 0;
                    $gray_border = 0x888888;
                    $image = imagecreatetruecolor(503, 155);
                    $rows = [];
                    for ($i = 0; $i < 30; $i++) {
                        $rows[] = ['timestamp' => 1792713600 - $i * 86400, 'value' => $value, 'map' => $i < 15 ? 'cp_dustbowl' : 'ctf_2fort'];
                    }
                    drawItems($image, ['width' => 503, 'height' => 155, 'indent_x' => [35, 35], 'indent_y' => [15, 15]], $rows, 0, 'value', 1, 1, 1, 1, [0xff0000, 0x00ff00, 0xffffff, 0xaaaaaa, 0x444444]);
                    imagepng($image, $tmp . '/legacy.png');
                    check(getimagesize($tmp . '/legacy.png')[0] === 503, 'legacy graph is a decodable PNG');
                    if ($value == 0) {
                        $baselinePixels = 0;
                        for ($x = 35; $x < 468; $x++) {
                            $baselinePixels += imagecolorat($image, $x, 140) === 0xff0000 ? 1 : 0;
                        }
                        check($baselinePixels > 20, "zero series must draw along the baseline (type $bar_type range $server_load_type)");
                    }
                }
            }
        }
    });

    runCase('image map query stripping and flush', function () use ($tmp) {
        $chart = new pChart(380, 200);
        $chart->tmpFolder = $tmp . '/';
        $chart->setImageMap(true, 'map');
        $chart->addToImageMap(1, 2, 3, 4, 'Skill', 42, 'test');
        $chart->SaveImageMap();
        $map = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --map ' . escapeshellarg($tmp));
        check($map === "1,2,3,4,Skill,42\r", 'map contents');
        check(!file_exists($tmp . '/map'), 'map flush');
    });

    foreach (['drawFilledLineGraph', 'drawFilledCubicCurve', 'drawArea', 'drawOverlayBarGraph', 'drawRadarAxis', 'drawFilledRadar', 'drawBasicPieGraph', 'drawFlatPieGraph', 'drawPieGraph', 'setLabel'] as $method) {
        runCase("polygon/layer $method", function () use ($tmp, $method) {
            $data = new pData();
            $data->AddPoint(['1.25', '3.75', '2.125']);
            $data->AddPoint(['2.5', '1.125', '4.25'], 'Serie2');
            $data->AddAllSeries();
            $data->SetSerieName('First', 'Serie1');
            $data->SetSerieName('Second', 'Serie2');
            $chart = new pChart(380, 200);
            $chart->drawBackground(90, 90, 90);
            $chart->setGraphArea(50, 28, 339, 174);
            $chart->setFontProperties(__DIR__ . '/../web/hlstatsimg/sig/font/DejaVuSans.ttf', 7);
            $rows = $data->GetData();
            $description = $data->GetDataDescription();
            $chart->drawScale($rows, $description, SCALE_NORMAL, 213, 217, 221);
            $chart->drawGrid(1, true);
            $chart->Render($tmp . '/before-layer.png');
            if (strpos($method, 'Pie') !== false) {
                $data->RemoveSerie('Serie2');
                $chart->$method($rows, $data->GetDataDescription(), 190, 100, 50);
            } elseif ($method === 'drawArea') {
                $chart->drawArea($rows, 'Serie1', 'Serie2', 12, 34, 56);
            } elseif ($method === 'setLabel') {
                $chart->setLabel($rows, $description, 'Serie1', 1, 'Peak');
            } elseif ($method === 'drawFilledCubicCurve') {
                $chart->$method($rows, $description, .1, 50, false);
                $chart->$method($rows, $description, .1, 50, true);
            } elseif ($method === 'drawFilledLineGraph') {
                $chart->$method($rows, $description, 50, false);
                $chart->$method($rows, $description, 50, true);
            } else {
                $chart->$method($rows, $description);
            }
            $chart->Render($tmp . '/layer.png');
            $image = imagecreatefrompng($tmp . '/layer.png');
            check(imagesx($image) === 380 && imagesy($image) === 200, 'layer PNG dimensions');
            check(file_get_contents($tmp . '/layer.png') !== file_get_contents($tmp . '/before-layer.png'), 'chart must draw, not just render its axes');
        });
    }

    // Use the production trend construction block, not a second implementation.
    // Bootstrap, repository and HTTP cache are exercised separately on the real stack.
    $source = file_get_contents(__DIR__ . '/../web/trend_graph.php');
    $render = substr($source, strpos($source, '    // Create new graph'));
    $render = substr($render, 0, strpos($render, '    header('));
    define('IMAGE_PATH', __DIR__ . '/../web/hlstatsimg');
    foreach ([0, 1, 2, 3, 30] as $rows) {
        foreach (['fractional', 'zero', 'negative', 'flat'] as $kind) {
            runCase("trend $rows rows $kind", function () use ($tmp, $render, $rows, $kind) {
                $skillArr = $skillChange = $dateArr = [];
                for ($i = 0; $i < $rows; $i++) {
                    $skillArr[] = $kind === 'zero' ? '0' : (string) (1.125 + ($i % 3) * .137);
                    $skillChange[] = $kind === 'zero' ? '0' : (string) ($kind === 'negative' ? -17 - ($i % 3) * 9 : ($i % 3) * 13 - 8);
                    $dateArr[] = $i === 0 || $i === $rows - 1 ? 'Oct-' . ($i + 1) : '';
                }
                if ($kind === 'flat') {
                    $skillArr = array_fill(0, $rows, '1.5');
                    $skillChange = array_fill(0, $rows, '12');
                }
                $emptyData = $rows < 2;
                $bgColor = ['red' => 90, 'green' => 90, 'blue' => 90];
                $colorText = ['red' => 213, 'green' => 217, 'blue' => 221];
                $cache_image = "$tmp/trend-$rows-$kind.png";
                eval($render);
                $image = imagecreatefrompng($cache_image);
                check(imagesx($image) === 380 && imagesy($image) === 200, 'decodable 380x200 PNG');
                check(imagecolorat($image, 0, 0) === 0x5a5a5a, 'background preserved');
            });
        }
    }
} finally {
    foreach (glob($tmp . '/*') as $file) {
        unlink($file);
    }
    rmdir($tmp);
}
exit($failures ? 1 : 0);
