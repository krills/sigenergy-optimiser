// Utility functions with TypeScript types

/**
 * Format a number with optional unit
 */
export function formatNumber(
    value: number | undefined | null, 
    unit: string = ''
): string {
    if (value === undefined || value === null || isNaN(value)) {
        return 'N/A';
    }
    
    // Round to 1 decimal place
    const rounded = Math.round(value * 10) / 10;
    return `${rounded}${unit ? ' ' + unit : ''}`;
}

/**
 * Format a timestamp to a readable date string
 */
export function formatDateTime(
    timestamp: string | number | Date | null | undefined,
    options?: Intl.DateTimeFormatOptions
): string {
    if (!timestamp) return 'Unknown';
    
    try {
        const date = new Date(timestamp);
        return date.toLocaleString('en-US', {
            timeZone: 'Europe/Stockholm',
            ...options
        });
    } catch (error) {
        return 'Invalid date';
    }
}

/**
 * Get CSS class for system status
 */
export function getStatusClassName(status: string | undefined | null): string {
    if (!status) {
        return 'bg-gray-100 text-gray-800';
    }
    
    switch (status.toLowerCase()) {
        case 'normal':
            return 'bg-green-100 text-green-800';
        case 'standby':
            return 'bg-yellow-100 text-yellow-800';
        case 'fault':
        case 'offline':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
}

/**
 * Calculate percentage with safe division
 */
export function calculatePercentage(
    value: number | undefined | null,
    total: number | undefined | null
): number {
    if (!value || !total || total === 0) return 0;
    return Math.round((value / total) * 100);
}

/**
 * Type-safe array check
 */
export function isNonEmptyArray<T>(arr: T[] | undefined | null): arr is T[] {
    return Array.isArray(arr) && arr.length > 0;
}