<?php

namespace Gatling\ParserBundle;

class ReportGenerator
{
    private $config;

    private $reportFinder;

    public function __construct(ConfigurationInterface $config, ReportFinder $reportFinder)
    {
        $this->config = $config;
        $this->reportFinder = $reportFinder;
    }

    public function generate()
    {
        $reports = $this->reportFinder->find();

        $reports = array_filter($reports, function ($report) {
            try {
                $this->config->findLegend($report->getReportCode());
            } catch (InvalidLegendException $e) {
                return false;
            }

            return true;
        });

        $allPages = array_map(
            function ($report) {
                return array_combine(
                    array_map([$this->config, 'mapPageCode'], array_keys($report->getPages())),
                    array_values($report->getPages())
                );
            },
            $reports
        );

        array_unshift($allPages, $this->config->getPages());

        $commonPages = call_user_func_array(
            'array_intersect_key',
            $allPages
        );

        $result = [
            'legends' => $this->config->getLegends(),
            'filters' => $this->config->getFilters(),
            'pages' => $commonPages,
            'aggregateReport' => [
                [
                    'dataCode' => 'requests',
                    'label' => 'Requests served per Page',
                    'axis' => array_values($commonPages)
                ],
                [
                    'dataCode' => 'response',
                    'label' => 'Mean Response Time per Page',
                    'axis' => array_values($commonPages)
                ],
                [
                    'dataCode' => 'indicatorPercent',
                    'label' => 'Global Indicator Percent',
                    'axis' => ['<800ms', '>800ms <1200ms', '>1200ms', 'Failed']
                ],
                [
                    'dataCode' => 'indicatorCount',
                    'label' => 'Global Indicator Count',
                    'axis' => ['<800ms', '>800ms <1200ms', '>1200ms', 'Failed']
                ]
            ],
            'pageReport' => [
                [
                    'dataCode' => 'response',
                    'label' => '#Page Response Times',
                    'axis' => [
                        'Min',
                        '50pct',
                        '75pct',
                        '95pct',
                        '99pct',
                        'Max',
                        'Mean'
                    ]
                ],
                [
                    'dataCode' => 'indicatorPercent',
                    'label' => '#Page Indicator Percent',
                    'axis' => ['<800ms', '>800ms <1200ms', '>1200ms', 'Failed']
                ],
                [
                    'dataCode' => 'indicatorCount',
                    'label' => '#Page Indicator Count',
                    'axis' => ['<800ms', '>800ms <1200ms', '>1200ms', 'Failed']
                ]
            ]
        ];

        /** @var ReportReader $report */
        foreach ($reports as $report) {
            $legend = $this->config->findLegend($report->getReportCode());
            $reportResult = [
                'code' => $report->getReportCode(),
                'path' => $report->getReportPath(),
                'legend' => $legend,
                'color' => $this->config->getLegends()[$legend],
                'position' => array_search($legend, array_keys($this->config->getLegends()))
            ];

            foreach (array_keys($this->config->getFilters()) as $filterCode) {
                $reportResult['filter'][$filterCode] = $this->config->findFilterValue(
                    $filterCode,
                    $report->getReportCode()
                );
            }

            $mappedCodes = [];

            foreach (array_keys($report->getPages()) as $pageCode) {
                $mappedCodes[$this->config->mapPageCode($pageCode)] = $pageCode;
            }

            $reportResult['aggregateReport']['requests'] = [];
            $reportResult['aggregateReport']['response'] = [];

            $opinionStat = $report->fetchGlobalOpinionStat();
            $reportResult['aggregateReport']['indicatorPercent'] = $opinionStat['percent'];
            $reportResult['aggregateReport']['indicatorCount'] = $opinionStat['count'];

            $reportResult['pageReport']['response'] = [];
            $reportResult['pageReport']['indicatorPercent'] = [];
            $reportResult['pageReport']['indicatorCount'] = [];

            foreach (array_keys($commonPages) as $pageCode) {
                $reportResult['aggregateReport']['requests'][] = $report
                    ->fetchNumberOfPageRequestsStat($mappedCodes[$pageCode]);
                $reportResult['aggregateReport']['response'][] = $report
                    ->fetchMeanResponseStat($mappedCodes[$pageCode]);

                $opinionStat = $report->fetchOpinionStat($mappedCodes[$pageCode]);
                $reportResult['pageReport']['response'][$pageCode] = array_values(
                    $report->fetchResponseStat($mappedCodes[$pageCode])
                );
                $reportResult['pageReport']['indicatorPercent'][$pageCode] = $opinionStat['percent'];
                $reportResult['pageReport']['indicatorCount'][$pageCode] = $opinionStat['count'];
            }


            $result['reports'][] = $reportResult;
        }

        return $result;
    }
}
