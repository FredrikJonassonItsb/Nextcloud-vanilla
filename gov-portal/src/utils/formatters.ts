import { format, formatDistanceToNow, isToday, isTomorrow, isYesterday, parseISO } from 'date-fns';
import { sv } from 'date-fns/locale';

/**
 * Format a date relative to now in Swedish
 */
export function formatRelativeTime(date: string | Date): string {
  const dateObj = typeof date === 'string' ? parseISO(date) : date;

  return formatDistanceToNow(dateObj, {
    addSuffix: true,
    locale: sv,
  });
}

/**
 * Format a date with smart labels (Idag, Igår, etc.)
 */
export function formatSmartDate(date: string | Date): string {
  const dateObj = typeof date === 'string' ? parseISO(date) : date;

  if (isToday(dateObj)) {
    return `Idag ${format(dateObj, 'HH:mm', { locale: sv })}`;
  }

  if (isTomorrow(dateObj)) {
    return `Imorgon ${format(dateObj, 'HH:mm', { locale: sv })}`;
  }

  if (isYesterday(dateObj)) {
    return `Igår ${format(dateObj, 'HH:mm', { locale: sv })}`;
  }

  return format(dateObj, 'd MMM HH:mm', { locale: sv });
}

/**
 * Format a date for display (full date)
 */
export function formatFullDate(date: string | Date): string {
  const dateObj = typeof date === 'string' ? parseISO(date) : date;
  return format(dateObj, 'EEEE d MMMM yyyy', { locale: sv });
}

/**
 * Format time only
 */
export function formatTime(date: string | Date): string {
  const dateObj = typeof date === 'string' ? parseISO(date) : date;
  return format(dateObj, 'HH:mm', { locale: sv });
}

/**
 * Format file size in human-readable format
 */
export function formatFileSize(bytes: number): string {
  if (bytes === 0) return '0 B';

  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));

  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`;
}

/**
 * Format a number with thousand separators (Swedish style)
 */
export function formatNumber(num: number): string {
  return num.toLocaleString('sv-SE');
}

/**
 * Truncate text with ellipsis
 */
export function truncateText(text: string, maxLength: number): string {
  if (text.length <= maxLength) return text;
  return text.substring(0, maxLength - 3) + '...';
}

/**
 * Get initials from a name
 */
export function getInitials(name: string): string {
  return name
    .split(' ')
    .map((part) => part.charAt(0))
    .join('')
    .toUpperCase()
    .substring(0, 2);
}

/**
 * Format a greeting based on time of day
 */
export function getGreeting(): string {
  const hour = new Date().getHours();

  if (hour < 5) return 'God natt';
  if (hour < 10) return 'God morgon';
  if (hour < 12) return 'God förmiddag';
  if (hour < 18) return 'God eftermiddag';
  if (hour < 22) return 'God kväll';
  return 'God natt';
}

/**
 * Format a duration in minutes to human-readable format
 */
export function formatDuration(minutes: number): string {
  if (minutes < 60) {
    return `${minutes} min`;
  }

  const hours = Math.floor(minutes / 60);
  const mins = minutes % 60;

  if (mins === 0) {
    return `${hours} tim`;
  }

  return `${hours} tim ${mins} min`;
}
