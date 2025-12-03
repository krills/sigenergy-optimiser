/**
 * Clean converter functions to transform raw API responses into strict typed models
 */

import type {
  SolarSystem,
  Device,
  DeviceCollection,
  EnergyFlow,
  SystemStatus,
  DeviceStatus,
  GridStatus,
  DeviceType,
  PowerMeasurement,
  EnergyMeasurement,
  Percentage,
  Timestamp
} from './models';

// Helper functions for creating typed values
export const createPowerMeasurement = (value: number, unit: 'kW' | 'W' | 'MW' = 'kW'): PowerMeasurement => ({
  value,
  unit
});

export const createEnergyMeasurement = (value: number, unit: 'kWh' | 'Wh' | 'MWh' = 'kWh'): EnergyMeasurement => ({
  value,
  unit
});

export const createPercentage = (value: number): Percentage => ({
  value: Math.max(0, Math.min(100, value))
});

export const createTimestamp = (unix: number): Timestamp => ({
  unix,
  iso: new Date(unix * 1000).toISOString()
});

// Status converters
export const parseSystemStatus = (status: unknown): SystemStatus => {
  const statusStr = String(status || 'normal').toLowerCase();
  if (statusStr === 'standby') return 'Standby';
  if (statusStr === 'fault') return 'Fault';
  if (statusStr === 'offline') return 'Offline';
  return 'normal';
};

export const parseDeviceStatus = (status: unknown): DeviceStatus => {
  const statusStr = String(status || 'normal').toLowerCase();
  if (statusStr === 'standby') return 'Standby';
  if (statusStr === 'fault') return 'Fault';
  if (statusStr === 'offline') return 'Offline';
  if (statusStr === 'shutdown') return 'Shutdown';
  if (statusStr === 'dormancy') return 'Dormancy';
  return 'normal';
};

export const parseGridStatus = (status: unknown): GridStatus => {
  return String(status) === 'offGrid' ? 'offGrid' : 'onGrid';
};

export const parseDeviceType = (type: unknown): DeviceType => {
  const typeStr = String(type || '').toLowerCase();
  if (typeStr.includes('battery') || typeStr.includes('ess') || typeStr.includes('storage')) return 'Battery';
  if (typeStr.includes('inverter') || typeStr.includes('aio') || typeStr.includes('hybrid')) return 'Inverter';
  if (typeStr.includes('gateway') || typeStr.includes('hub') || typeStr.includes('comm')) return 'Gateway';
  if (typeStr.includes('meter') || typeStr.includes('monitor') || typeStr.includes('sensor')) return 'Meter';
  if (typeStr.includes('charger')) return 'DcCharger';
  return 'Inverter'; // Default fallback
};

// Main converters
export const convertRawSystem = (raw: Record<string, unknown>): SolarSystem => {
  const systemId = String(raw.systemId || '');
  const systemName = String(raw.systemName || 'Unknown System');
  
  return {
    identity: {
      id: systemId,
      name: systemName
    },
    
    status: {
      value: parseSystemStatus(raw.status),
      isActive: Boolean(raw.isActivate || raw.isActive),
      gridConnection: parseGridStatus(raw.onOffGridStatus)
    },
    
    location: {
      address: String(raw.addr || raw.address || ''),
      timeZone: String(raw.timeZone || 'UTC')
    },
    
    capacity: {
      solar: {
        installedCapacity: createPowerMeasurement(Number(raw.pvCapacity) || 0, 'kW'),
        peakCapacity: createPowerMeasurement(Number(raw.pvCapacity) || 0, 'kW')
      },
      battery: {
        totalCapacity: createEnergyMeasurement(Number(raw.batteryCapacity) || 0, 'kWh'),
        usableCapacity: createEnergyMeasurement((Number(raw.batteryCapacity) || 0) * 0.9, 'kWh')
      },
      inverter: {
        ratedPower: createPowerMeasurement(Number(raw.pvCapacity) || 0, 'kW'),
        maxPower: createPowerMeasurement((Number(raw.pvCapacity) || 0) * 1.1, 'kW'),
        efficiency: createPercentage(95) // Default assumption
      }
    },
    
    installation: {
      gridConnectionDate: createTimestamp(Number(raw.gridConnectTime || raw.gridConnectedTime) || 0)
    },
    
    devices: convertRawDeviceCollection(raw.devices as Record<string, unknown>, raw.rawDevices as Record<string, unknown>[]),
    
    lastUpdate: createTimestamp(Math.floor(Date.now() / 1000))
  };
};

export const convertRawDevice = (raw: Record<string, unknown>): Device => {
  return {
    identity: {
      serialNumber: String(raw.serialNumber || ''),
      partNumber: raw.pn ? String(raw.pn) : undefined,
      systemId: String(raw.systemId || '')
    },
    
    type: parseDeviceType(raw.deviceType),
    status: parseDeviceStatus(raw.status),
    
    firmware: {
      version: String(raw.firmwareVersion || 'Unknown')
    },
    
    attributes: raw.attrMap as Record<string, unknown> | undefined
  };
};

export const convertRawDeviceCollection = (
  devices: Record<string, unknown> | undefined,
  rawDevices: Record<string, unknown>[] | undefined
): DeviceCollection => {
  const deviceList = (rawDevices || []).map(convertRawDevice);
  
  return {
    total: Number(devices?.total) || deviceList.length,
    batteries: deviceList.filter(d => d.type === 'Battery'),
    inverters: deviceList.filter(d => d.type === 'Inverter'),
    gateways: deviceList.filter(d => d.type === 'Gateway'),
    meters: deviceList.filter(d => d.type === 'Meter'),
    other: deviceList.filter(d => !['Battery', 'Inverter', 'Gateway', 'Meter'].includes(d.type))
  };
};

export const convertRawEnergyFlow = (raw: Record<string, unknown>): EnergyFlow => {
  const gridPowerValue = Number(raw.gridPower) || 0;
  const batteryPowerValue = Number(raw.batteryPower) || 0;
  
  return {
    timestamp: createTimestamp(Math.floor(Date.now() / 1000)),
    
    solar: {
      generation: createPowerMeasurement(Number(raw.pvPower) || 0)
    },
    
    grid: {
      power: createPowerMeasurement(gridPowerValue),
      isImporting: gridPowerValue > 0,
      isExporting: gridPowerValue < 0
    },
    
    battery: {
      power: createPowerMeasurement(batteryPowerValue),
      stateOfCharge: createPercentage(Number(raw.batterySoc) || 0),
      isCharging: batteryPowerValue > 0,
      isDischarging: batteryPowerValue < 0
    },
    
    load: {
      consumption: createPowerMeasurement(Number(raw.loadPower) || 0)
    },
    
    other: {
      evCharging: createPowerMeasurement(Number(raw.evPower) || 0),
      heatPump: createPowerMeasurement(Number(raw.heatPumpPower) || 0)
    }
  };
};

// Helper to convert API response lists
export const convertRawSystemList = (rawSystems: Record<string, unknown>[]): readonly SolarSystem[] => {
  return rawSystems.map(convertRawSystem);
};

// Type guards for validation
export const isValidPowerMeasurement = (value: unknown): value is PowerMeasurement => {
  return (
    typeof value === 'object' &&
    value !== null &&
    'value' in value &&
    'unit' in value &&
    typeof (value as any).value === 'number' &&
    ['kW', 'W', 'MW'].includes((value as any).unit)
  );
};

export const isValidEnergyMeasurement = (value: unknown): value is EnergyMeasurement => {
  return (
    typeof value === 'object' &&
    value !== null &&
    'value' in value &&
    'unit' in value &&
    typeof (value as any).value === 'number' &&
    ['kWh', 'Wh', 'MWh'].includes((value as any).unit)
  );
};

export const isValidPercentage = (value: unknown): value is Percentage => {
  return (
    typeof value === 'object' &&
    value !== null &&
    'value' in value &&
    typeof (value as any).value === 'number' &&
    (value as any).value >= 0 &&
    (value as any).value <= 100
  );
};