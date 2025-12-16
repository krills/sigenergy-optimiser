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

export interface ChargeInterval {
  timestamp: number; // Unix timestamp in milliseconds
  power: number; // Charging power in kW
  reason: string; // Reason for charging
  price: number; // Price at this interval
}

export interface BatterySchedule {
  schedule?: Array<{
    action: string;
    start_time: any;
    end_time: any;
    power: number;
    reason: string;
    price: number;
    target_soc: number;
  }>;
  chargeIntervals: ChargeInterval[];
  analysis?: {
    stats: any;
    charge_opportunities: number;
    discharge_opportunities: number;
    price_volatility: number;
  };
  summary?: {
    total_intervals: number;
    charge_intervals: number;
    discharge_intervals: number;
    idle_intervals: number;
    charge_hours: number;
    discharge_hours: number;
    estimated_savings: number;
    estimated_earnings: number;
    net_benefit: number;
    efficiency_utilized: number;
  };
  generated_at?: string;
  current_soc?: number;
  error?: string | null;
}

export interface CurrentBatteryMode {
  mode: 'charge' | 'discharge' | 'idle' | 'unknown';
  status: 'active' | 'inactive' | 'error';
  power_kw?: number | null;
  battery_soc?: number | null;
  battery_power?: number | null;
  last_updated?: string;
  error?: string;
}

// Inertia.js Page Props Interface
export interface PageProps {
  authenticated: boolean;
  authError?: string | null;
  systems?: SigenEnergySystem[];
  lastUpdated?: string | null;
  cacheInfo?: CacheInfo | null;
  electricityPrices?: {
    prices: Array<{
      timestamp: number;
      price: number;
      hour: string;
    }>;
    loading: boolean;
    error?: string;
    lastUpdated: string;
    provider?: {
      name: string;
      description: string;
      area: string;
      granularity: string;
    };
  };
  batterySchedule?: BatterySchedule;
  currentBatteryMode?: CurrentBatteryMode;
  // Index signature to satisfy Inertia's PageProps constraint
  [key: string]: any;
}