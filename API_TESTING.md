# Sigenergy API Testing

This project includes HTTP test files for directly testing the Sigenergy API endpoints without going through the Laravel application.

## Files

- `test.http` - HTTP requests for authentication and battery control
- `http-client.env.json` - Environment variables for different environments
- `API_TESTING.md` - This documentation file

## Setup

1. **Select Environment**
   
   Credentials are already configured in `http-client.env.json`. Simply select the "local" environment in your HTTP client:
   - **VS Code**: Install "REST Client" extension → Select environment in bottom status bar
   - **IntelliJ/PHPStorm**: Select environment from dropdown near requests

2. **System ID is Automatic**
   
   The system ID is now fetched automatically! The "Get System List" request will retrieve all your systems and automatically use the first one for subsequent commands.

## Available Requests

### 1. Authentication
```http
POST /openapi/auth/login/password
```
- Authenticates with Sigenergy API
- Stores access token automatically for subsequent requests
- Token expires in ~12 hours

### 2. Battery Control Commands

#### Charge Battery
```http
POST /openapi/instruction/command
```
- Sets battery to charging mode
- Power: 3.0 kW (configurable)
- Duration: 15 minutes (900 seconds)

#### Discharge Battery
```http
POST /openapi/instruction/command
```
- Sets battery to discharging mode
- Power: 3.0 kW (configurable)
- Duration: 15 minutes (900 seconds)

#### Set Battery Idle
```http
POST /openapi/instruction/command
```
- Sets battery to idle mode (no charging/discharging)

### 3. System Information

#### Get System List
```http
GET /openapi/system
```
- Lists all systems associated with your account
- Automatically stores the first system ID for subsequent requests
- Displays detailed system information (name, address, capacity)

#### Get Energy Flow
```http
GET /openapi/systems/{systemId}/energyFlow
```
- Shows current battery status, SOC, power flows
- Real-time system data

## Usage Instructions

1. **VS Code with REST Client Extension**
   - Install the "REST Client" extension
   - Open `test.http`
   - Click "Send Request" above each request

2. **IntelliJ IDEA / PHPStorm**
   - Open `test.http`
   - Click the green arrow next to each request

3. **Other HTTP Clients**
   - Copy the requests to Postman, Insomnia, or curl commands
   - Replace `{{variables}}` with actual values

## Example Workflow

1. **Authenticate** - Get access token
2. **Get System List** - Fetch your systems and store system ID automatically  
3. **Check Status** - Get current energy flow to see battery SOC
4. **Send Command** - Charge/discharge/idle based on current state
5. **Verify** - Check energy flow again to confirm command was executed

## Response Codes

- `0` - Success
- `1000` - Parameter illegal
- `11003` - Authentication failed
- `1501` - Failed to execute command
- `1201` - Rate limit exceeded

## Rate Limits

- Authentication: No specific limit
- Commands: Maximum 1 per 5 minutes per system
- Data requests: Once per 5 minutes per endpoint

## Troubleshooting

1. **"Unsubstituted variable" Errors**
   - **VS Code**: Install "REST Client" extension → Select "local" environment in bottom status bar
   - **IntelliJ/PHPStorm**: Select "local" from environment dropdown near requests  
   - **Other clients**: Replace `{{SIGENERGY_BASE_URL}}` with `https://api-eu.sigencloud.com` manually
   - **Environment**: Ensure "local" environment is selected (credentials are pre-configured)

2. **Authentication Failed**
   - Check username/password in `http-client.env.json`
   - Ensure account is not locked (5 failed attempts = 3 min lockout)
   - Verify you're using correct environment (local/dev/production)

3. **Command Failed**
   - Check system ID is correct (run "Get System List" first)
   - Verify system is online and accessible
   - Respect rate limits (wait 5 minutes between commands)
   - Check if system is in VPP mode (not controllable)

4. **Token Expired**
   - Re-run authentication request
   - Tokens expire after ~12 hours

5. **System ID Missing**
   - Run "Get System List" request first to auto-populate `{{system_id}}`
   - Check that your account has registered systems

## Security Notes

- Never commit credentials to version control
- Use different environments for testing vs production
- Tokens are cached and reused automatically
- Production credentials should be kept secure

## Example Responses

### Successful Authentication
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "tokenType": "Bearer",
    "accessToken": "HgrU1Rn2CVUx4rV8C7zpEIF...",
    "expiresIn": 43199
  }
}
```

### Successful Battery Command
```json
{
  "code": 0,
  "msg": "success",
  "data": null
}
```

### Current Energy Status
```json
{
  "code": 0,
  "msg": "success", 
  "data": {
    "pvPower": 0.0,
    "gridPower": 0.0,
    "batteryPower": -1.3,
    "batterySoc": 50,
    "loadPower": 1.3
  }
}
```