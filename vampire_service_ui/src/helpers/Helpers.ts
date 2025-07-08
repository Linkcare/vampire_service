import { format as dateFormatter } from "date-fns";

/*
 * Formats a value as a string for display in the table.
 * Handles null, undefined, string, number, and Date types.
 */
function formatAsString(value: any): string {
  if (value === null || value === undefined) {
    return "";
  }
  if (typeof value === "string") {
    return value;
  } else if (typeof value === "number") {
    return value.toString();
  } else if (value instanceof Date) {
    return value.toLocaleDateString(undefined, {
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
    });
  }
  return String(value);
}
function dateFormat(date: Date | null, format?: string): string | null {
  if (!date) {
    return null;
  }
  format = format || "yyyy-MM-dd HH:mm:ss";
  return dateFormatter(date, format);
}

function isValidDate(dateStr: string | null): boolean {
  if (!dateStr) return false;

  // Comprobamos el formato con expresión regular
  const regex = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/;
  const match = dateStr.match(regex);
  if (!match) return false;

  const day = parseInt(match[1], 10);
  const month = parseInt(match[2], 10);
  const year = parseInt(match[3], 10);

  // Creamos la fecha con el constructor (ojo: mes empieza en 0)
  const date = new Date(year, month - 1, day);

  // Check invalid dates
  if (
    date.getFullYear() !== year ||
    date.getMonth() !== month - 1 ||
    date.getDate() !== day
  ) {
    return false;
  }
  // Do not allow future dates
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  date.setHours(0, 0, 0, 0);

  return date <= today; // true si es pasada o hoy
}

function isValidTime(timeStr: string | null): boolean {
  if (!timeStr) return false;

  const regex = /^(\d{1,2}):(\d{1,2})(:(\d{1,2}))?$/;
  const match = timeStr.match(regex);
  if (!match) return false;

  const hours = parseInt(match[1], 10);
  const minutes = parseInt(match[2], 10);
  const seconds = match[4] ? parseInt(match[4], 10) : 0;

  return (
    hours >= 0 &&
    hours < 24 &&
    minutes >= 0 &&
    minutes < 60 &&
    seconds >= 0 &&
    seconds < 60
  );
}

function composeDatetime(
  dateStr: string | null,
  timeStr: string | null
): Date | null {
  if (!dateStr || !isValidDate(dateStr)) return null;
  if (!timeStr) {
    timeStr = "00:00"; // Default time if not provided
  }
  if (!isValidTime(timeStr)) return null;

  const dateParts = dateStr.split("/");
  const timeParts = timeStr.split(":");

  if (dateParts.length !== 3 || timeParts.length < 2) return null;

  const day = parseInt(dateParts[0], 10);
  const month = parseInt(dateParts[1], 10) - 1;
  const year = parseInt(dateParts[2], 10);
  const hours = parseInt(timeParts[0], 10);
  const minutes = parseInt(timeParts[1], 10);
  const seconds = timeParts.length > 2 ? parseInt(timeParts[2], 10) : 0;

  return new Date(year, month, day, hours, minutes, seconds);
}

export {
  formatAsString,
  dateFormat,
  isValidDate,
  isValidTime,
  composeDatetime,
};
