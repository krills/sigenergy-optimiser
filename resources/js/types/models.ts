/**
 * Clean, strict TypeScript models for API data consumption
 * Read-only DTOs with exact primitive types - no JSON serialization needed
 */

// Status enums with exact values
export type SystemStatus = 'Standby' | 'normal' | 'Fault' | 'Offline';
export type DeviceStatus = 'Standby' | 'normal' | 'Fault' | 'Dormancy' | 'Offline' | 'Shutdown';
export type GridStatus = 'onGrid' | 'offGrid';
export type DeviceType = 'Inverter' | 'Battery' | 'Gateway' | 'DcCharger' | 'AcCharger' | 'Meter';

// Exact measurement types with units
export interface PowerMeasurement {
  readonly value: number;
  readonly unit: 'kW' | 'W' | 'MW';
}

export interface EnergyMeasurement {
  readonly value: number;
  readonly unit: 'kWh' | 'Wh' | 'MWh';
}

export interface VoltageMeasurement {
  readonly value: number;
  readonly unit: 'V' | 'kV';
}

export interface CurrentMeasurement {
  readonly value: number;
  readonly unit: 'A' | 'mA';
}

export interface FrequencyMeasurement {
  readonly value: number;
  readonly unit: 'Hz';
}

export interface TemperatureMeasurement {
  readonly value: number;
  readonly unit: 'C' | 'F' | 'K';
}

export interface Percentage {
  readonly value: number; // 0-100
}

export interface PowerFactor {
  readonly value: number; // -1 to 1
}

// Time types
export interface Timestamp {
  readonly unix: number; // Unix timestamp in seconds
  readonly iso: string; // ISO string representation
}

export interface Duration {
  readonly seconds: number;
  readonly hours: number;
  readonly minutes: number;
}

// Identity types
export interface SystemIdentifier {
  readonly id: string;
  readonly name: string;
}

export interface DeviceIdentifier {
  readonly serialNumber: string;
  readonly partNumber?: string;
  readonly systemId: string;
}

// System capacity specifications
export interface SystemCapacity {
  readonly solar: {
    readonly installedCapacity: PowerMeasurement;
    readonly peakCapacity: PowerMeasurement;
  };
  readonly battery: {
    readonly totalCapacity: EnergyMeasurement;
    readonly usableCapacity: EnergyMeasurement;
  };
  readonly inverter: {
    readonly ratedPower: PowerMeasurement;
    readonly maxPower: PowerMeasurement;
    readonly efficiency: Percentage;
  };
}

// Location information
export interface Location {
  readonly address: string;
  readonly timeZone: string;
}

// Device model
export interface Device {
  readonly identity: DeviceIdentifier;
  readonly type: DeviceType;
  readonly status: DeviceStatus;
  readonly firmware: {
    readonly version: string;
  };
  readonly attributes?: {
    // Battery attributes
    readonly ratedEnergy?: number; // kWh
    readonly ratedChargePower?: number; // kW
    readonly ratedDischargePower?: number; // kW
    
    // Inverter attributes
    readonly ratedActivePower?: number; // kW
    readonly maxActivePower?: number; // kW
    readonly ratedVoltage?: number; // V
    readonly ratedFrequency?: number; // Hz
    readonly pvStringNumber?: number;
    
    // Generic attributes
    readonly [key: string]: unknown;
  };
}

// Categorized device collection
export interface DeviceCollection {
  readonly total: number;
  readonly batteries: readonly Device[];
  readonly inverters: readonly Device[];
  readonly gateways: readonly Device[];
  readonly meters: readonly Device[];
  readonly other: readonly Device[];
}

// Real-time energy flow snapshot
export interface EnergyFlow {
  readonly timestamp: Timestamp;
  readonly solar: {
    readonly generation: PowerMeasurement;
  };
  readonly grid: {
    readonly power: PowerMeasurement; // Positive = importing, Negative = exporting
    readonly isImporting: boolean;
    readonly isExporting: boolean;
  };
  readonly battery: {
    readonly power: PowerMeasurement; // Positive = charging, Negative = discharging
    readonly stateOfCharge: Percentage;
    readonly isCharging: boolean;
    readonly isDischarging: boolean;
  };
  readonly load: {
    readonly consumption: PowerMeasurement;
  };
  readonly other: {
    readonly evCharging: PowerMeasurement;
    readonly heatPump: PowerMeasurement;
  };
}

// Complete solar system model
export interface SolarSystem {
  readonly identity: SystemIdentifier;
  readonly status: {
    readonly value: SystemStatus;
    readonly isActive: boolean;
    readonly gridConnection: GridStatus;
  };
  readonly location: Location;
  readonly capacity: SystemCapacity;
  readonly installation: {
    readonly gridConnectionDate: Timestamp;
  };
  readonly devices: DeviceCollection;
  readonly realtimeFlow?: EnergyFlow;
  readonly lastUpdate: Timestamp;
}

// Page props for Inertia.js
export interface DashboardPageProps {
  readonly authenticated: boolean;
  readonly authError?: string;
  readonly systems: readonly SolarSystem[];
  readonly lastUpdated?: string;
  readonly cacheInfo?: {
    readonly nextUpdate: string;
    readonly dataAge: number;
  };
}

// API response wrapper (renamed to avoid conflict)
export interface ApiResponseWrapper<T> {
  readonly success: boolean;
  readonly data?: T;
  readonly error?: string;
  readonly timestamp?: string;
}