// Sigenergy API Type Definitions

export interface SigenEnergyDevice {
  systemId: string;
  serialNumber: string;
  deviceType: string;
  status?: string;
  pn?: string;
  firmwareVersion?: string;
  attrMap?: Record<string, any>;
}

export interface SigenEnergySystem {
  systemId: string;
  systemName: string;
  addr?: string;
  address?: string;
  status: 'normal' | 'Standby' | 'Fault' | 'Offline' | string;
  isActivate: boolean;
  isActive?: boolean;
  onOffGridStatus: 'onGrid' | 'offGrid' | string;
  timeZone?: string;
  gridConnectTime?: number;
  gridConnectedTime?: number;
  pvCapacity?: number;
  batteryCapacity?: number;
  devices?: {
    total: number;
    batteries: SigenEnergyDevice[];
    inverters: SigenEnergyDevice[];
    gateways: SigenEnergyDevice[];
    meters: SigenEnergyDevice[];
    other: SigenEnergyDevice[];
  };
  rawDevices?: SigenEnergyDevice[];
}

export interface EnergyFlowData {
  pvPower?: number;
  gridPower?: number;
  evPower?: number;
  loadPower?: number;
  heatPumpPower?: number;
  batteryPower?: number;
  batterySoc?: number;
}

export interface CacheInfo {
  nextUpdate?: string;
  dataAge?: number;
}

export interface DashboardProps {
  authenticated: boolean;
  authError?: string | null;
  systems?: SigenEnergySystem[];
  lastUpdated?: string | null;
  cacheInfo?: CacheInfo | null;
}

export interface ApiResponse<T = any> {
  success: boolean;
  data?: T;
  error?: string;
  timestamp?: string;
}

// Inertia.js Page Props Interface
export interface PageProps {
  authenticated: boolean;
  authError?: string | null;
  systems?: SigenEnergySystem[];
  lastUpdated?: string | null;
  cacheInfo?: CacheInfo | null;
  // Index signature to satisfy Inertia's PageProps constraint
  [key: string]: any;
}