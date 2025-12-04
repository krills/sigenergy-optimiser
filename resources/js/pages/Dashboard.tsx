import { useState, useEffect } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import type { PageProps, SigenEnergySystem, EnergyFlowData, ApiResponse } from '@/types/sigenergy';
import { formatNumber, formatDateTime, getStatusClassName } from '@/utils/formatters';
import PriceChart from '@/components/PriceChart';

interface RealtimeDataState {
  [systemId: string]: EnergyFlowData;
}

function Dashboard() {
    const { authenticated, authError, systems, lastUpdated, cacheInfo, electricityPrices, batterySchedule } = usePage<PageProps>().props;
    const [realtimeData, setRealtimeData] = useState<RealtimeDataState>({});
    const [isRefreshing, setIsRefreshing] = useState<boolean>(false);

    // Function to fetch realtime data for a system
    const fetchRealtimeData = async (systemId: string): Promise<void> => {
        try {
            const response = await fetch(`/api/system/${systemId}/energy-flow`);
            const data: ApiResponse<EnergyFlowData> = await response.json();
            
            if (data.success) {
                setRealtimeData(prev => ({
                    ...prev,
                    [systemId]: data.data || {}
                }));
            }
        } catch (error) {
            console.error('Error fetching realtime data:', error);
        }
    };

    // Auto-refresh realtime data every 30 seconds
    useEffect(() => {
        if (!authenticated || !systems?.length) return;

        const interval = setInterval(() => {
            systems.forEach(system => {
                fetchRealtimeData(system.systemId);
            });
        }, 30000);

        // Initial fetch
        systems.forEach(system => {
            fetchRealtimeData(system.systemId);
        });

        return () => clearInterval(interval);
    }, [authenticated, systems]);

    const handleRefresh = (): void => {
        setIsRefreshing(true);
        router.post('/refresh', {}, {
            onFinish: () => setIsRefreshing(false)
        });
    };

    // Helper functions are now imported from utils

    // Error state - authentication failed
    if (!authenticated) {
        return (
            <>
                <Head title="Dashboard - Authentication Error" />
                <div className="min-h-screen bg-gray-50">
                    <nav className="bg-white shadow">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="flex h-16 justify-between">
                                <div className="flex">
                                    <div className="flex flex-shrink-0 items-center">
                                        <h1 className="text-xl font-bold text-red-600">Sigenergy Admin Dashboard</h1>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </nav>

                    <main>
                        <div className="mx-auto max-w-7xl py-6 sm:px-6 lg:px-8">
                            <div className="px-4 py-6 sm:px-0">
                                <div className="bg-red-50 border border-red-200 rounded-lg p-8">
                                    <div className="flex items-center">
                                        <div className="flex-shrink-0">
                                            <svg className="h-8 w-8 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                            </svg>
                                        </div>
                                        <div className="ml-3">
                                            <h3 className="text-lg font-medium text-red-800">
                                                Authentication Failed
                                            </h3>
                                            <p className="mt-2 text-sm text-red-700">
                                                {authError || 'Unable to connect to Sigenergy API.'}
                                            </p>
                                            <div className="mt-4">
                                                <button
                                                    onClick={() => window.location.reload()}
                                                    className="bg-red-100 hover:bg-red-200 text-red-800 font-medium py-2 px-4 rounded"
                                                >
                                                    Retry Authentication
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </main>
                </div>
            </>
        );
    }

    // Success state - authenticated and showing data
    return (
        <>
            <Head title="Sigenergy Dashboard" />
            
            <div className="min-h-screen bg-gray-50">
                <nav className="bg-white shadow">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                        <div className="flex h-16 justify-between items-center">
                            <div className="flex">
                                <div className="flex flex-shrink-0 items-center">
                                    <h1 className="text-xl font-bold text-green-600">✅ Sigenergy Dashboard</h1>
                                </div>
                            </div>
                            <div className="flex items-center space-x-4">
                                {lastUpdated && (
                                    <span className="text-sm text-gray-500">
                                        Updated: {formatDateTime(lastUpdated, { timeStyle: 'medium' })}
                                    </span>
                                )}
                                <button
                                    onClick={handleRefresh}
                                    disabled={isRefreshing}
                                    className="bg-blue-500 hover:bg-blue-600 disabled:bg-blue-300 text-white px-4 py-2 rounded text-sm font-medium transition-colors"
                                >
                                    {isRefreshing ? 'Refreshing...' : 'Refresh Data'}
                                </button>
                            </div>
                        </div>
                    </div>
                </nav>

                <main>
                    <div className="mx-auto max-w-7xl py-6 sm:px-6 lg:px-8">
                        <div className="px-4 py-6 sm:px-0">
                            
                            {/* Electricity Price Chart */}
                            {electricityPrices && (
                                <div className="mb-8">
                                    <PriceChart 
                                        prices={electricityPrices.prices || []}
                                        chargeIntervals={batterySchedule?.chargeIntervals || []}
                                        loading={electricityPrices.loading}
                                        error={electricityPrices.error}
                                        provider={electricityPrices.provider}
                                    />
                                </div>
                            )}

                            {/* Battery Optimization Schedule */}
                            {batterySchedule && !batterySchedule.error && (
                                <div className="mb-8 bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-6">
                                    <h3 className="text-lg font-semibold text-blue-900 mb-4">🔋 Battery Optimization Schedule</h3>
                                    
                                    {batterySchedule.summary && (
                                        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                                            <div className="bg-white rounded-lg p-3 text-center shadow-sm">
                                                <div className="text-lg font-bold text-green-600">
                                                    {batterySchedule.summary.charge_intervals}
                                                </div>
                                                <div className="text-sm text-gray-600">Charge Intervals</div>
                                                <div className="text-xs text-gray-500">
                                                    {batterySchedule.summary.charge_hours.toFixed(1)}h total
                                                </div>
                                            </div>
                                            <div className="bg-white rounded-lg p-3 text-center shadow-sm">
                                                <div className="text-lg font-bold text-orange-600">
                                                    {batterySchedule.summary.discharge_intervals}
                                                </div>
                                                <div className="text-sm text-gray-600">Discharge Intervals</div>
                                                <div className="text-xs text-gray-500">
                                                    {batterySchedule.summary.discharge_hours.toFixed(1)}h total
                                                </div>
                                            </div>
                                            <div className="bg-white rounded-lg p-3 text-center shadow-sm">
                                                <div className="text-lg font-bold text-blue-600">
                                                    {batterySchedule.current_soc?.toFixed(0) || 'N/A'}%
                                                </div>
                                                <div className="text-sm text-gray-600">Current SOC</div>
                                                <div className="text-xs text-gray-500">Battery level</div>
                                            </div>
                                            <div className="bg-white rounded-lg p-3 text-center shadow-sm">
                                                <div className={`text-lg font-bold ${batterySchedule.summary.net_benefit >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                                    {batterySchedule.summary.net_benefit >= 0 ? '+' : ''}{batterySchedule.summary.net_benefit.toFixed(2)} SEK
                                                </div>
                                                <div className="text-sm text-gray-600">Est. Daily Benefit</div>
                                                <div className="text-xs text-gray-500">
                                                    {(batterySchedule.summary.efficiency_utilized * 100).toFixed(0)}% efficiency
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {batterySchedule.analysis && (
                                        <div className="text-sm text-blue-800">
                                            <p><strong>Price Analysis:</strong> {batterySchedule.analysis.charge_opportunities} charge opportunities, {batterySchedule.analysis.discharge_opportunities} discharge opportunities</p>
                                            <p><strong>Generated:</strong> {batterySchedule.generated_at ? new Date(batterySchedule.generated_at).toLocaleTimeString() : 'Unknown'}</p>
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* Battery Schedule Error */}
                            {batterySchedule?.error && (
                                <div className="mb-8 bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                                    <div className="flex items-center text-yellow-700">
                                        <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <div>
                                            <h3 className="font-medium">Battery Schedule Unavailable</h3>
                                            <p className="text-sm text-yellow-600">{batterySchedule.error}</p>
                                        </div>
                                    </div>
                                </div>
                            )}
                            
                            {/* Cache Info */}
                            {cacheInfo && (
                                <div className="mb-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
                                    <div className="flex items-center">
                                        <svg className="h-5 w-5 text-blue-400 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <span className="text-sm text-blue-800">
                                            Data cached for 5 minutes. Next update: {
                                                formatDateTime(cacheInfo.nextUpdate, { timeStyle: 'medium' })
                                            }
                                        </span>
                                    </div>
                                </div>
                            )}

                            {/* Systems Overview */}
                            <div className="mb-8">
                                <h2 className="text-2xl font-bold text-gray-900 mb-4">
                                    Solar Energy Systems ({systems?.length || 0})
                                </h2>
                                
                                {!systems?.length ? (
                                    <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-6">
                                        <p className="text-yellow-800">No systems found. Check your Sigenergy account configuration.</p>
                                    </div>
                                ) : (
                                    <div className="grid gap-6">
                                        {systems.map((system: SigenEnergySystem) => (
                                            <div key={system.systemId} className="bg-white border border-gray-200 rounded-lg shadow">
                                                {/* System Header */}
                                                <div className="px-6 py-4 border-b border-gray-200">
                                                    <div className="flex justify-between items-start">
                                                        <div>
                                                            <h3 className="text-lg font-medium text-gray-900">
                                                                {system.systemName}
                                                            </h3>
                                                            <p className="text-sm text-gray-500">ID: {system.systemId}</p>
                                                            {(system.address || system.addr) && (
                                                                <p className="text-sm text-gray-500">📍 {system.address || system.addr}</p>
                                                            )}
                                                        </div>
                                                        <div className="text-right">
                                                            <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded-full ${getStatusClassName(system.status)}`}>
                                                                {system.status}
                                                            </span>
                                                            <div className="mt-1 text-sm text-gray-500">
                                                                {(system.isActive ?? system.isActivate) ? '🟢 Active' : '🔴 Inactive'}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                {/* System Stats */}
                                                <div className="px-6 py-4">
                                                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                                                        <div className="text-center p-3 bg-gray-50 rounded">
                                                            <div className="text-lg font-bold text-gray-900">
                                                                {formatNumber(system.pvCapacity, 'kW')}
                                                            </div>
                                                            <div className="text-sm text-gray-500">PV Capacity</div>
                                                        </div>
                                                        <div className="text-center p-3 bg-gray-50 rounded">
                                                            <div className="text-lg font-bold text-gray-900">
                                                                {formatNumber(system.batteryCapacity, 'kWh')}
                                                            </div>
                                                            <div className="text-sm text-gray-500">Battery Capacity</div>
                                                        </div>
                                                        <div className="text-center p-3 bg-gray-50 rounded">
                                                            <div className="text-lg font-bold text-gray-900">
                                                                {system.onOffGridStatus}
                                                            </div>
                                                            <div className="text-sm text-gray-500">Grid Status</div>
                                                        </div>
                                                        <div className="text-center p-3 bg-gray-50 rounded">
                                                            <div className="text-lg font-bold text-gray-900">
                                                                {system.devices?.total || 0}
                                                            </div>
                                                            <div className="text-sm text-gray-500">Total Devices</div>
                                                        </div>
                                                    </div>

                                                    {/* Real-time Energy Flow */}
                                                    {realtimeData[system.systemId] && (
                                                        <div className="mb-4 p-4 bg-blue-50 border border-blue-200 rounded">
                                                            <h4 className="font-medium text-blue-900 mb-2">⚡ Real-time Energy Flow</h4>
                                                            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                                                                <div>
                                                                    <span className="font-medium">PV Power:</span>
                                                                    <span className="ml-1 text-green-600">
                                                                        {formatNumber(realtimeData[system.systemId].pvPower, 'kW')}
                                                                    </span>
                                                                </div>
                                                                <div>
                                                                    <span className="font-medium">Grid Power:</span>
                                                                    <span className={`ml-1 ${(realtimeData[system.systemId].gridPower || 0) >= 0 ? 'text-red-600' : 'text-green-600'}`}>
                                                                        {formatNumber(realtimeData[system.systemId].gridPower, 'kW')}
                                                                    </span>
                                                                </div>
                                                                <div>
                                                                    <span className="font-medium">Battery:</span>
                                                                    <span className="ml-1 text-blue-600">
                                                                        {formatNumber(realtimeData[system.systemId].batterySoc, '% SOC')}
                                                                    </span>
                                                                </div>
                                                                <div>
                                                                    <span className="font-medium">Load:</span>
                                                                    <span className="ml-1 text-gray-600">
                                                                        {formatNumber(realtimeData[system.systemId].loadPower, 'kW')}
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    )}

                                                    {/* Devices Breakdown */}
                                                    {system.devices && (
                                                        <div>
                                                            <h4 className="font-medium text-gray-900 mb-3">🔧 Device Breakdown ({system.devices.total} total)</h4>
                                                            
                                                            {/* Quick Summary */}
                                                            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
                                                                <div className="text-center p-3 bg-green-50 border border-green-200 rounded">
                                                                    <div className="text-lg font-bold text-green-800">
                                                                        {system.devices.batteries?.length || 0}
                                                                    </div>
                                                                    <div className="text-sm text-green-600">Batteries</div>
                                                                </div>
                                                                <div className="text-center p-3 bg-blue-50 border border-blue-200 rounded">
                                                                    <div className="text-lg font-bold text-blue-800">
                                                                        {system.devices.inverters?.length || 0}
                                                                    </div>
                                                                    <div className="text-sm text-blue-600">Inverters</div>
                                                                </div>
                                                                <div className="text-center p-3 bg-purple-50 border border-purple-200 rounded">
                                                                    <div className="text-lg font-bold text-purple-800">
                                                                        {system.devices.gateways?.length || 0}
                                                                    </div>
                                                                    <div className="text-sm text-purple-600">Gateways</div>
                                                                </div>
                                                                <div className="text-center p-3 bg-yellow-50 border border-yellow-200 rounded">
                                                                    <div className="text-lg font-bold text-yellow-800">
                                                                        {system.devices.meters?.length || 0}
                                                                    </div>
                                                                    <div className="text-sm text-yellow-600">Meters</div>
                                                                </div>
                                                                <div className="text-center p-3 bg-gray-50 border border-gray-200 rounded">
                                                                    <div className="text-lg font-bold text-gray-800">
                                                                        {system.devices.other?.length || 0}
                                                                    </div>
                                                                    <div className="text-sm text-gray-600">Other</div>
                                                                </div>
                                                            </div>

                                                            {/* Detailed Device List */}
                                                            {system.rawDevices && system.rawDevices.length > 0 && (
                                                                <div className="mt-4">
                                                                    <h5 className="font-medium text-gray-800 mb-2">📋 Device Details</h5>
                                                                    <div className="space-y-2 max-h-64 overflow-y-auto">
                                                                        {system.rawDevices.map((device, deviceIndex) => (
                                                                            <div key={`${device.serialNumber}-${deviceIndex}`} 
                                                                                 className="bg-gray-50 border border-gray-200 rounded p-3 text-sm">
                                                                                <div className="flex justify-between items-start">
                                                                                    <div className="flex-1">
                                                                                        <div className="font-medium text-gray-900">
                                                                                            {device.deviceType || 'Unknown Device'}
                                                                                        </div>
                                                                                        <div className="text-gray-500">
                                                                                            S/N: {device.serialNumber}
                                                                                        </div>
                                                                                        {device.pn && (
                                                                                            <div className="text-gray-500">
                                                                                                P/N: {device.pn}
                                                                                            </div>
                                                                                        )}
                                                                                        {device.firmwareVersion && (
                                                                                            <div className="text-gray-500">
                                                                                                FW: {device.firmwareVersion}
                                                                                            </div>
                                                                                        )}
                                                                                    </div>
                                                                                    <div className="ml-2 text-right">
                                                                                        <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded-full ${getStatusClassName(device.status)}`}>
                                                                                            {device.status || 'Unknown'}
                                                                                        </span>
                                                                                    </div>
                                                                                </div>
                                                                                
                                                                                {/* Show important attributes if available */}
                                                                                {device.attrMap && Object.keys(device.attrMap).length > 0 && (
                                                                                    <div className="mt-2 pt-2 border-t border-gray-300">
                                                                                        <div className="text-xs text-gray-600 space-y-1">
                                                                                            {device.attrMap.ratedEnergy && (
                                                                                                <div>Capacity: {formatNumber(device.attrMap.ratedEnergy, 'kWh')}</div>
                                                                                            )}
                                                                                            {device.attrMap.ratedActivePower && (
                                                                                                <div>Power: {formatNumber(device.attrMap.ratedActivePower, 'kW')}</div>
                                                                                            )}
                                                                                            {device.attrMap.pvStringNumber && (
                                                                                                <div>PV Strings: {device.attrMap.pvStringNumber}</div>
                                                                                            )}
                                                                                        </div>
                                                                                    </div>
                                                                                )}
                                                                            </div>
                                                                        ))}
                                                                    </div>
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {/* Footer */}
                            <div className="text-center text-sm text-gray-500 mt-8">
                                <p>🌟 Powered by Sigenergy API • Built with Laravel & Inertia.js</p>
                                <p>Rate limited to 1 request per 5 minutes per endpoint • Data cached for optimal performance</p>
                            </div>
                        </div>
                    </div>
                </main>
            </div>
        </>
    );
}

export default Dashboard;