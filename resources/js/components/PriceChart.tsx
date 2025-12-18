import { useRef } from 'react';
import Highcharts from 'highcharts';
import HighchartsReact from 'highcharts-react-official';

interface PriceData {
  timestamp: number; // Unix timestamp
  price: number; // SEK/kWh
  hour: string; // Human readable hour
}

interface ChargeInterval {
  timestamp: number; // Unix timestamp in milliseconds
  power: number; // Charging power in kW
  reason: string; // Reason for charging
  price: number; // Price at this interval
}

interface PriceTiers {
  cheapest_threshold: number;
  middle_threshold: number;
  cheapest_tier: [number, number];
  middle_tier: [number, number];
  expensive_tier: [number, number];
}

interface BatteryHistory {
  soc_history: Array<{
    timestamp: number;
    soc: number;
    interval_start: string;
  }>;
  charge_history: Array<{
    timestamp: number;
    power: number;
    price: number;
    decision_source: string;
    interval_start: string;
  }>;
  total_intervals: number;
  charge_intervals: number;
  last_updated: string;
  error?: string;
}

interface PriceChartProps {
  prices: PriceData[];
  chargeIntervals?: ChargeInterval[];
  batteryHistory?: BatteryHistory;
  priceTiers?: PriceTiers;
  loading?: boolean;
  error?: string;
  provider?: {
    name: string;
    description: string;
    area: string;
    granularity: string;
  };
}

export default function PriceChart({ prices, chargeIntervals = [], batteryHistory, priceTiers, loading = false, error, provider }: PriceChartProps) {
  const chartRef = useRef<HighchartsReact.RefObject>(null);

  const chartOptions: Highcharts.Options = {
    title: {
      text: 'Swedish Electricity Prices',
      style: {
        fontSize: '16px',
        fontWeight: '600',
        color: '#1f2937'
      }
    },

    subtitle: {
      text: provider?.description || 'Electricity prices with 15-minute intervals - Today',
      style: {
        fontSize: '12px',
        color: '#6b7280'
      }
    },

    chart: {
      type: 'line',
      height: 300,
      backgroundColor: 'transparent',
      spacing: [10, 10, 15, 10]
    },

    time: {
      timezone: 'Europe/Stockholm'
    },

    xAxis: {
      type: 'datetime',
      title: {
        text: 'Time',
        style: { color: '#6b7280', fontSize: '12px' }
      },
      labels: {
        format: '{value:%H:%M}',
        style: { color: '#6b7280', fontSize: '11px' }
      },
      gridLineColor: '#e5e7eb',
      plotLines: [{
        value: Date.now(), // Current time in milliseconds
        color: '#dc2626', // Red color for visibility
        width: 2,
        dashStyle: 'Dash',
        label: {
          text: 'Now',
          style: {
            color: '#dc2626',
            fontSize: '11px',
            fontWeight: 'bold'
          },
          align: 'center',
          x: 0,
          y: -5
        },
        zIndex: 5 // Make sure the line appears above other chart elements
      }]
    },

    yAxis: [
      {
        // Primary y-axis for price
        title: {
          text: 'Price (SEK/kWh)',
          style: { color: '#6b7280', fontSize: '12px' }
        },
        labels: {
          format: '{value:.3f}',
          style: { color: '#6b7280', fontSize: '11px' }
        },
        gridLineColor: '#e5e7eb',
        plotBands: priceTiers ? [
          {
            from: priceTiers.cheapest_tier[0],
            to: priceTiers.cheapest_tier[1],
            color: 'rgba(34, 197, 94, 0.15)',
            label: {
              text: 'Cheapest Third',
              style: { color: '#22c55e', fontSize: '10px' }
            }
          },
          {
            from: priceTiers.middle_tier[0],
            to: priceTiers.middle_tier[1],
            color: 'transparent',
            label: {
              text: 'Middle Third',
              style: { color: '#6b7280', fontSize: '10px' }
            }
          },
          {
            from: priceTiers.expensive_tier[0],
            to: priceTiers.expensive_tier[1],
            color: 'rgba(239, 68, 68, 0.15)',
            label: {
              text: 'Most Expensive Third',
              style: { color: '#ef4444', fontSize: '10px' }
            }
          }
        ] : []
      },
      {
        // Secondary y-axis for charging power
        title: {
          text: 'Charging Power (kW)',
          style: { color: '#3b82f6', fontSize: '12px' }
        },
        labels: {
          format: '{value:.1f}',
          style: { color: '#3b82f6', fontSize: '11px' }
        },
        opposite: true,
        gridLineWidth: 0,
        min: 0,
        max: 5 // Typical max charging power
      },
      {
        // Third y-axis for SOC percentage
        title: {
          text: 'SOC (%)',
          style: { color: '#f59e0b', fontSize: '12px' }
        },
        labels: {
          format: '{value}%',
          style: { color: '#f59e0b', fontSize: '11px' }
        },
        opposite: false,
        gridLineWidth: 0,
        min: 0,
        max: 100,
        offset: 50 // Offset from left side to avoid collision with price axis
      }
    ],

    tooltip: {
      shared: true,
      formatter: function() {
        const points = this.points || [];
        if (points.length === 0) return '';

        const currentTime = Date.now();
        const pointTime = points[0].x;
        const timeDiff = Math.abs(currentTime - pointTime);
        const isCurrentTime = timeDiff < (15 * 60 * 1000); // Within 15 minutes = current interval

        let tooltip = `<b>${Highcharts.dateFormat('%H:%M', pointTime)}</b>`;
        if (isCurrentTime) {
          tooltip += ` <span style="color: #dc2626; font-weight: bold;">⏰ NOW</span>`;
        }
        tooltip += '<br/>';

        points.forEach(point => {
          if (point.series.name === 'Electricity Price') {
            tooltip += `Price: <b>${point.y?.toFixed(3)} SEK/kWh</b><br/>`;
            tooltip += `<span style="color: ${point.y! < 0.15 ? '#22c55e' : point.y! > 1.5 ? '#ef4444' : '#f59e0b'}">
              ${point.y! < 0.15 ? '🟢 Cheap - Good for charging' :
                point.y! > 1.5 ? '🔴 Expensive - Use battery' :
                '🟡 Medium price'}
            </span><br/>`;
          } else if (point.series.name === 'Battery Charging') {
            tooltip += `<span style="color: #3b82f6">🔋 Planned Charging: <b>${point.y?.toFixed(1)} kW</b></span><br/>`;
          } else if (point.series.name === 'Actual Charging') {
            tooltip += `<span style="color: #22c55e">🔋 Actually Charged: <b>${point.y?.toFixed(1)} kW</b></span><br/>`;
          } else if (point.series.name === 'Battery SOC') {
            tooltip += `<span style="color: #f59e0b">⚡ SOC: <b>${point.y?.toFixed(1)}%</b></span><br/>`;
          }
        });

        return tooltip;
      },
      backgroundColor: 'rgba(255, 255, 255, 0.95)',
      borderColor: '#e5e7eb',
      borderRadius: 8,
      shadow: true
    },

    legend: {
      enabled: chargeIntervals.length > 0 || (batteryHistory?.soc_history?.length ?? 0) > 0,
      layout: 'horizontal',
      align: 'center',
      verticalAlign: 'bottom',
      itemStyle: {
        fontSize: '12px',
        color: '#6b7280'
      }
    },

    plotOptions: {
      line: {
        lineWidth: 2,
        marker: {
          enabled: true,
          radius: 3,
          symbol: 'circle'
        },
        states: {
          hover: {
            lineWidth: 3
          }
        }
      },
      column: {
        pointPadding: 0,
        groupPadding: 0,
        borderWidth: 0,
        opacity: 0.7
      }
    },

    series: [
      {
        type: 'line',
        name: 'Electricity Price',
        data: prices.map(price => [
          price.timestamp * 1000, // Convert to milliseconds for Highcharts
          price.price
        ]),
        color: '#3b82f6',
        zones: [
          {
            value: 0.15,
            color: '#22c55e' // Green for cheap prices
          },
          {
            value: 1.5,
            color: '#f59e0b' // Orange for medium prices
          },
          {
            color: '#ef4444' // Red for expensive prices
          }
        ]
      },
      // SOC curve - always show if we have data
      ...((batteryHistory?.soc_history?.length ?? 0) > 0 ? [{
        type: 'line' as const,
        name: 'Battery SOC',
        data: batteryHistory!.soc_history.map(soc => [
          soc.timestamp, // Already in milliseconds
          soc.soc
        ]),
        color: '#f59e0b',
        yAxis: 2, // Use third y-axis for SOC percentage
        lineWidth: 3,
        marker: {
          enabled: true,
          radius: 4,
          symbol: 'circle'
        },
        tooltip: {
          valueSuffix: '%'
        }
      }] : []),
      // Planned charging intervals (blue with lower opacity)
      ...(chargeIntervals.length > 0 ? [{
        type: 'column' as const,
        name: 'Planned Charging',
        data: chargeIntervals.map(interval => [
          interval.timestamp, // Already in milliseconds
          interval.power
        ]),
        color: 'rgba(59, 130, 246, 0.4)', // Blue with lower transparency for planned
        yAxis: 1, // Use secondary y-axis for power
        tooltip: {
          valueSuffix: ' kW'
        },
        zIndex: 1 // Lower z-index so actual charging appears on top
      }] : []),
      // Actual charging intervals (greener color with higher opacity)
      ...((batteryHistory?.charge_history?.length ?? 0) > 0 ? [{
        type: 'column' as const,
        name: 'Actual Charging',
        data: batteryHistory!.charge_history.map(charge => [
          charge.timestamp, // Already in milliseconds
          charge.power
        ]),
        color: 'rgba(34, 197, 94, 0.8)', // Green with higher opacity for actual
        yAxis: 1, // Use secondary y-axis for power
        tooltip: {
          valueSuffix: ' kW'
        },
        zIndex: 2 // Higher z-index so it appears on top of planned charging
      }] : [])
    ],

    credits: {
      enabled: false
    },

    responsive: {
      rules: [{
        condition: {
          maxWidth: 768
        },
        chartOptions: {
          chart: {
            height: 250
          },
          title: {
            style: { fontSize: '14px' }
          },
          subtitle: {
            style: { fontSize: '11px' }
          }
        }
      }]
    }
  };

  if (loading) {
    return (
      <div className="bg-white rounded-lg border border-gray-200 p-6">
        <div className="animate-pulse">
          <div className="h-4 bg-gray-200 rounded w-1/3 mb-2"></div>
          <div className="h-3 bg-gray-200 rounded w-1/4 mb-4"></div>
          <div className="h-64 bg-gray-200 rounded"></div>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="bg-white rounded-lg border border-red-200 p-6">
        <div className="flex items-center text-red-600">
          <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
          </svg>
          <div>
            <h3 className="font-medium">Price Data Error</h3>
            <p className="text-sm text-red-500">{error}</p>
          </div>
        </div>
      </div>
    );
  }

  if (prices.length === 0) {
    return (
      <div className="bg-white rounded-lg border border-gray-200 p-6">
        <div className="text-center text-gray-500">
          <h3 className="font-medium text-gray-900 mb-2">No Price Data Available</h3>
          <p className="text-sm">Waiting for Nord Pool electricity price data...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="bg-white rounded-lg border border-gray-200 p-6 shadow-sm">
      <HighchartsReact
        ref={chartRef}
        highcharts={Highcharts}
        options={chartOptions}
      />

      {/* Price Summary */}
      <div className="mt-4 grid grid-cols-3 gap-4 text-sm">
        <div className="text-center">
          <div className="font-medium text-green-600">
            {Math.min(...prices.map(p => p.price)).toFixed(3)} SEK
          </div>
          <div className="text-gray-500">Min Today</div>
        </div>
        <div className="text-center">
          <div className="font-medium text-blue-600">
            {(prices.reduce((sum, p) => sum + p.price, 0) / prices.length).toFixed(3)} SEK
          </div>
          <div className="text-gray-500">Average</div>
        </div>
        <div className="text-center">
          <div className="font-medium text-red-600">
            {Math.max(...prices.map(p => p.price)).toFixed(3)} SEK
          </div>
          <div className="text-gray-500">Max Today</div>
        </div>
      </div>
    </div>
  );
}
