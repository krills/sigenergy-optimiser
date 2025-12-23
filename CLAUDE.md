# Coding Instructions
1. Be extremely reserved with code comments. Only use comments for timing intervals or multi-step operations.
2. Strive for 100% typing. PHP DTOs should break down API responses to primitives. TypeScript types should mirror PHP DTOs.
3. Build simple first, add complexity when needed.
4. When code gets complex, consider refactoring/simplifying.
5. Use services and interfaces through dependency injection.
6. Avoid duplicate code - break out reusable logic into helpers.
7. Don't build or serve while developing - assume they're running in parallel.

# Stockholm Solar Battery Optimization

## Overview
Optimize an 8 kWh battery system in Stockholm using:
- **Sigenergy Cloud API** for battery control
- **Nord Pool electricity prices** for optimization decisions
- **Laravel + React** for monitoring and control

## Current Implementation Status
✅ **Battery Controller**: 15-minute optimization cycles with separation of concerns
✅ **Price Integration**: Multi-provider system with mgrey.se (ENTSO-E) as primary provider
✅ **Dashboard**: Real-time monitoring with price charts and battery schedule  
✅ **Database**: Battery history tracking with 15-minute intervals
✅ **Commands**: Production-ready optimization commands

## Key Services Architecture

### **BatteryControllerCommand** (Orchestration & Safety)
- **Purpose**: System orchestration, safety checks, and command execution
- **Responsibilities**: 
  - Fetch system state from Sigenergy API
  - Coordinate with BatteryPlanner for decisions
  - Apply safety overrides (SOC limits, operational constraints)
  - Execute commands via Sigenergy API
  - Log results to database
- **Command**: `php artisan app:send-instruction`

### **BatteryPlanner** (Price-Based Optimization)
- **Purpose**: Pure price analysis and optimization decisions
- **Responsibilities**:
  - Analyze electricity price patterns (3-tier system)
  - Calculate optimal charge/discharge timing
  - Factor in current SOC, solar production, and load
  - Target 5kW consistent grid consumption during charging
  - Return comprehensive decision with price context
- **Emergency Logic**: SOC ≤ 10% triggers immediate charging

### **SigenEnergyApiService** (Hardware Interface)
- **Purpose**: Direct communication with Sigenergy Cloud API
- **Capabilities**: MQTT and REST API support for real-time control
- **Rate Limits**: Respects 5-minute intervals between commands

### **Price Provider Integration**
- **Primary**: mgrey.se (ENTSO-E Transparency Platform)
- **Fallback**: Multiple provider consensus system available
- **Real-time**: 15-minute interval pricing for SE3 (Stockholm) zone

## System Configuration
- **Location**: Stockholm, Sweden (SE3 pricing zone)
- **Capacity**: 8 kWh battery + solar panels
- **Grid**: Fortum (net metering)
- **System ID**: Retrieved dynamically from Sigenergy API

## API Integration

### Sigenergy Cloud API
- **Service**: `SigenEnergyApiService.php` - Authentication and battery control
- **Authentication**: Bearer token (12h expiry, auto-refresh)
- **Rate Limit**: 10 requests/min, 5-minute intervals between system calls
- **Base URL**: `https://api.sigencloud.com`

### Environment Variables
```env
SIGENERGY_BASE_URL=https://api.sigencloud.com
SIGENERGY_USERNAME=your@email.com
SIGENERGY_PASSWORD=your_password
```

## Key API Error Codes
- `0`: Success
- `1000`: Invalid parameters
- `1104`: Device offline  
- `1106`: System not found
- `1501`: Command execution failed
- `11003`: Authentication failed

## Main API Endpoints
- `GET /openapi/system` - Get system list
- `GET /openapi/systems/{systemId}/energyFlow` - Real-time energy data
- `POST /openapi/instruction/command` - Battery control commands
- `GET /openapi/instruction/{systemId}/settings` - Current operation mode

## Battery Control Modes
- `0` (MSC): Maximum Self-Consumption (default)
- `5` (FFG): Fully Feed-in to Grid (sell all solar)
- `charge`: Force battery charging
- `discharge`: Force battery discharge  
- `idle`: No charging/discharging
- `selfConsumption`: PV priority for charging

## Real-time Data APIs

### Energy Flow Data
- `pvPower`: Solar generation (kW)
- `gridPower`: Grid import/export (kW, positive=import)
- `loadPower`: Home consumption (kW)
- `batteryPower`: Battery charge/discharge (kW, positive=charging)
- `batterySoc`: State of charge (0-100%)

### Device Real-time Data
Detailed device metrics including:
- Battery SOC, charge/discharge power and daily energy totals
- PV generation (current and daily totals)
- Grid frequency, voltage, and power factor
- Internal temperature and diagnostics

## Battery Control Commands

### Operation Modes
- `0` (MSC): Maximum Self-Consumption (default)
- `5` (FFG): Fully Feed-in to Grid (sell all solar)
- `charge`: Force charging with specified power
- `discharge`: Force discharging with specified power
- `idle`: No active charging/discharging

### Command Structure
```json
{
  "systemId": "NDXZZ1731665796",
  "activeMode": "charge",
  "startTime": 1715154185,
  "duration": 3600,
  "chargingPower": 3.0
}
```



## Telemetry Signals Reference

### **Power Flow Signals (Critical for Optimization)**
- `gridActivePowerW`: Grid active power (positive = importing, negative = exporting)
- `gridReactivePowerW`: Grid reactive power at connection point
- `pvPowerW`: **Total PV solar power output** (key for solar vs grid decisions)
- `storageChargeDischargePowerW`: **Battery power flow** (positive = charging, negative = discharging)
- `storageSOC%`: **Battery state of charge** (0-100%, critical for optimization timing)

### **Grid Connection Signals (Three-Phase)**
- `gridPhaseAActivePowerW` / `gridPhaseBActivePowerW` / `gridPhaseCActivePowerW`: Per-phase grid power
- `gridPhaseAReactivePowerVar` / `gridPhaseBReactivePowerVar` / `gridPhaseCReactivePowerVar`: Per-phase reactive power

### **Inverter Signals (Three-Phase)**  
- `inverterActivePowerW`: Total inverter active power output
- `inverterReactivePowerVar`: Total inverter reactive power
- `inverterPhaseAActivePowerW` / `inverterPhaseBActivePowerW` / `inverterPhaseCActivePowerW`: Per-phase inverter power
- `inverterPhaseAReactivePowerVar` / `inverterPhaseBReactivePowerVar` / `inverterPhaseCReactivePowerVar`: Per-phase reactive power

### **System Capacity Signals (For Algorithm Limits)**
- `storageChargeCapacityWh`: Available battery charging capacity
- `storageDischargeCapacityWh`: Available battery discharging capacity  
- `batteryMaxChargePowerW`: **Maximum battery charging power limit**
- `batteryMaxDischargePowerW`: **Maximum battery discharging power limit**
- `batteryRatedCapabilityWh`: **Total system battery capacity** (8kWh for Stockholm system)

### **Grid & Inverter Limits**
- `gridMaxBackfeedPowerW`: Maximum allowed power export to grid
- `inverterMaxFeedInActivePowerW`: Maximum inverter power to grid
- `inverterMaxAbsorptionActivePowerW`: Maximum power absorption from grid
- `inverterMaxFeedInReactivePowerVar`: Maximum reactive power to grid
- `inverterMaxAbsorptionReactivePowerVar`: Maximum reactive power absorption

### **System Status Signals**
- `onOffGridStatus`: Grid connection status (onGrid/offGrid)
- `systemStatus`: Overall system status (standby/running/fault/shutdown/disconnected)
- `inverterMaxActivePowerW`: Base value for active power adjustments
- `inverterMaxApprentPowerVar`: Base value for reactive power adjustments
- `batteryRatedChargePowerW`: Safe maximum charging power
- `batteryRatedDischargePowerW`: Safe maximum discharging power

**🎯 Critical for Stockholm Optimization Algorithms:**

**Real-time Decision Inputs:**
1. `storageSOC%` - When to charge/discharge (39% needs charging)
2. `pvPowerW` - Solar availability for optimization decisions  
3. `gridActivePowerW` - Current import/export status (-0.115kW exporting)
4. `storageChargeDischargePowerW` - Current battery activity (-0.001kW minimal)

**Safety & Limit Constraints:**
1. `batteryMaxChargePowerW` / `batteryMaxDischargePowerW` - Power limits for commands
2. `gridMaxBackfeedPowerW` - Grid export limits
3. `systemStatus` - Only optimize when "running"
4. `onOffGridStatus` - Ensure grid connection for price optimization

**This telemetry data provides the COMPLETE picture** for intelligent optimization algorithms! 🎯

## Energy Storage Operating Modes (DETAILED)

### **CHARGE Mode**
**Purpose**: Prioritize PV energy for battery charging, then use grid

**Operation Logic:**
1. **High Solar**: Use PV exclusively for battery charging, export excess to grid
2. **Medium Solar**: Use all PV for charging + draw additional power from grid  
3. **Low/No Solar**: Charge battery from grid power only

**Power Limits Support:**
- `gridPurchasingPowerLimit`: Limit grid import during charging
- `gridFeedingPowerLimit`: Limit grid export of excess solar

**🎯 Perfect for**: Cheap Nord Pool electricity periods (charge from grid when rates low)

### **DISCHARGE Mode** 
**Purpose**: Prioritize PV for discharge operations, then use battery

**Operation Logic:**
1. **High Solar**: Use PV power for home consumption first
2. **Insufficient PV**: Supplement with battery discharge for home loads
3. **Grid Export Control**: Limit backflow power to grid if required

**🎯 Perfect for**: Expensive Nord Pool periods (avoid grid import, use stored energy)

### **SELF-CONSUMPTION Mode**
**Purpose**: Maximize self-use of solar energy with battery storage backup

**Operation Logic:**
1. **High Solar**: PV → Home Load → Battery Charging → Grid Export (in priority order)
2. **Low Solar**: Battery Discharge → Home Load (avoid grid import)

**🎯 Perfect for**: Normal operations when Nord Pool prices are medium/average

### **SELF-CONSUMPTION-GRID Mode**  
**Purpose**: Maximize solar revenue by prioritizing grid sales over battery storage

**Operation Logic:**
1. **High Solar**: PV → Home Load → **Grid Export** (battery charging secondary)
2. **Low Solar**: Battery Discharge → Home Load

**🎯 Perfect for**: High Nord Pool prices (sell all excess solar, use battery for home)

### **IDLE Mode**
**Purpose**: Battery maintenance, pure solar-to-grid export

**Operation Logic:**
1. **Battery**: No charging or discharging (maintain current SOC)
2. **Solar**: All excess PV power exported directly to grid
3. **Grid Limits**: Can limit maximum export power if required

**🎯 Perfect for**: Battery protection periods, grid maintenance, or SOC preservation

## **🔥 Stockholm Optimization Strategy Matrix**

| Nord Pool Price | Solar Production | Optimal Mode | Rationale |
|---|---|---|---|
| **Very Low** (0.05 SEK) | Any | **CHARGE** | Force charge from cheap grid |
| **Low** (0.15 SEK) | High | **SELF-CONSUMPTION** | Store solar, buy cheap grid |
| **Medium** (0.50 SEK) | High | **SELF-CONSUMPTION** | Normal optimization |
| **High** (1.50 SEK) | High | **SELF-CONSUMPTION-GRID** | Sell all solar, use battery |
| **Very High** (2.50 SEK) | Any | **DISCHARGE** | Use battery, avoid expensive grid |

**Real Stockholm Example (Winter, 39% SOC):**
- **02:00-06:00**: CHARGE mode with `chargePriorityType: "GRID"` (cheap rates)
- **06:00-15:00**: SELF-CONSUMPTION mode (normal day rates)  
- **15:00-20:00**: DISCHARGE mode (peak evening rates)
- **20:00-02:00**: SELF-CONSUMPTION mode (night rates)

This provides **surgical precision** for maximizing savings through intelligent mode selection! 🎯

## MQTT Real-time Data Streaming

### **MQTT Protocol Benefits**
- **Lightweight**: Minimal header overhead for resource-constrained devices
- **Real-time**: Instant message pushing for time-critical optimization
- **Reliability**: 3 QoS levels for guaranteed message delivery
- **Flexibility**: Publish/subscribe model for scalable, loosely-coupled communication

### **Connection Setup**
1. **Broker Server**: Connect to Sigenergy MQTT broker (provided connection details)
2. **Authentication**: Client ID, username, and password for secure connection
3. **Topic Subscription**: Subscribe to relevant data streams

### **Data Stream Topics**

#### **1. Real-time Operation Data Topic**
**Purpose**: Continuous monitoring data for optimization algorithms
**Data Types**:
- Voltage, current, power, frequency
- Battery SOC, charge/discharge power
- PV generation, grid import/export
- System energy flows

**Update Frequency**: Regular intervals (real-time)
**🎯 Perfect for**: Nord Pool price-based optimization decisions

#### **2. Rated Parameter & System Status Topic**  
**Purpose**: System configuration and status changes
**Data Types**:
- Device rated capacities and limits
- System operational status changes
- Configuration parameter updates

**Update Frequency**: On change only
**🎯 Perfect for**: Updating algorithm constraints and limits

#### **3. Alarm Information Topic**
**Purpose**: Critical system alerts and faults
**Data Types**:
- Device faults (high temperature, pressure abnormalities)
- System alarms with timestamps
- Device identification and alarm classification

**Update Frequency**: Immediate on fault detection
**🎯 Perfect for**: Pausing optimization during system issues

### **Stockholm Optimization Integration**

**Real-time Data Stream → Optimization Algorithm:**
```php
// Subscribe to real-time data for continuous optimization
$mqttClient->subscribe('realtime/[SYSTEM_ID]/power', function($data) {
    $batterySOC = $data['storageSOC%'];
    $gridPower = $data['gridActivePowerW'];
    $pvPower = $data['pvPowerW'];
    
    // Get current Nord Pool price
    $currentPrice = $nordPoolApi->getCurrentPrice();
    
    // Make optimization decision
    if ($batterySOC < 40 && $currentPrice < 0.15) {
        $api->forceChargeBatteryFromGrid($systemId, time(), 5.0, 3600);
    }
});

// Subscribe to alarms to pause optimization
$mqttClient->subscribe('alarms/[SYSTEM_ID]', function($alarm) {
    if ($alarm['severity'] === 'critical') {
        $optimizationEngine->pauseOptimization($alarm['systemId']);
    }
});
```

**Benefits for Stockholm Solar Project:**
- **Real-time Optimization**: Instant response to price changes
- **Fault Prevention**: Automatic optimization pause during system issues  
- **Continuous Monitoring**: 24/7 data stream for algorithm inputs
- **Reliable Delivery**: MQTT QoS ensures critical commands are delivered

## Current Implementation

### **Decision Flow Architecture**
1. **BatteryControllerCommand** fetches system state (SOC, solar, load)
2. **BatteryPlanner** analyzes prices and returns optimal decision with context
3. **Controller** applies safety checks and operational overrides
4. **Final decision** executed via Sigenergy API (MQTT preferred)
5. **Results logged** to BatteryHistory for analysis and cost tracking

### **Price-Based Optimization Strategy**
- **3-Tier Pricing**: Cheapest 33% → Charge, Middle 33% → Context-dependent, Expensive 33% → Discharge
- **Grid Load Management**: Target 5kW consumption during charging
- **Emergency Override**: SOC ≤ 10% forces immediate charging regardless of price
- **Evening Logic**: After 8 PM, allow discharge during middle-price periods

### Available Commands
```bash
# Production battery optimization
php artisan app:send-instruction --force

# Dry-run testing (safe)
php artisan app:send-instruction --dry-run

# Test with specific system
php artisan app:send-instruction --system-id=YOUR_SYSTEM_ID

# Legacy commands (deprecated)
# php artisan battery:controller
# php artisan send-instruction
```

### Database Models
- `BatteryHistory`: 15-minute interval logging with price analysis, SOC tracking, and cost metrics
- **Removed**: BatterySession model (consolidated into BatteryHistory)

### Dashboard Features
- **Real-time SOC**: Displays actual battery state from Sigenergy API (null if unavailable)
- **Price Charts**: 15-minute electricity price visualization with charge windows
- **Optimization Schedule**: Visual representation of planned charge/discharge periods
- **System Status**: Live energy flow monitoring (solar, grid, load, battery)

## Production Usage

### **Automated Scheduling**
The system runs every 15 minutes at quarter-hour intervals (00, 15, 30, 45 minutes past each hour).

**Setup cron job**:
```bash
# Add to crontab: crontab -e
* * * * * cd /path/to/solapp && php artisan schedule:run >> /dev/null 2>&1
```

### **Manual Execution**
```bash
# Production optimization (live API calls)
php artisan app:send-instruction --force

# Safe testing (no API calls)
php artisan app:send-instruction --dry-run --force

# Specific system override
php artisan app:send-instruction --system-id=YUFYB1763464060 --force
```

### **Decision Examples**
```bash
# Emergency charging (SOC ≤ 10%)
🎯 Final Decision: CHARGE
⚡ Power: 3.0 kW
🧠 Reason: Emergency charge - SOC critically low (targeting 5.0kW grid load)

# Price-based charging (cheap periods)
🎯 Final Decision: CHARGE  
⚡ Power: 2.3 kW
🧠 Reason: Very cheap price: 0.125 SEK/kWh (targeting 5.0kW grid load)

# Conservative idle (normal prices)
🎯 Final Decision: IDLE
⚡ Power: 0.0 kW
🧠 Reason: Price in middle tier, conserving for peak periods
```

### **Safety Overrides**
The controller can override planner decisions for safety:
- **Never charge** above 95% SOC
- **Never discharge** below 10% SOC  
- **Time-based restrictions** (if implemented)
- **System health checks** (if API reports errors)

## Development & Testing

### **Legacy Commands (Deprecated)**
These commands may still work but are not actively maintained:
```bash
# php artisan battery:controller          # Use app:send-instruction instead
# php artisan send-instruction            # Renamed to app:send-instruction  
# php artisan prices:multi-test           # Price testing (may work)
# php artisan battery:schedule            # Schedule display (may work)
```

### **Database Migration Notes**
- **Removed**: `battery_sessions` table and foreign key constraints
- **Active**: `battery_history` table with 15-minute interval tracking
- **Migration**: Cost tracking and price analysis fields added
