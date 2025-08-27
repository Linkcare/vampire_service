/*
 * DataTable.tsx
 * A generic data table component for displaying paginated data with filtering.
 *
 * npm install @chakra-ui/react @emotion/react
 * npx @chakra-ui/cli snippet add
 *
 * @param columns - Array of column definitions.
 *   Each column is an object with the members:
 *    - header :title of the column
 *    - accessor : one of the properties of the Object represented in each row.
 *   Optional properties include:
 *    - dateFormatter: Function to format date values. Prototype is (value: Date | null) => string | null.
 *    - filterable: Can be a Boolean or an object with interface CustomFilterProps.
 *      If a "true" boolean value is provided, the filter will be a plain <input> element
 *      If an object following the interface CustomFilterProps is provided, then it must have the following members:
 *        a) stateVariable: a tracked variable that can be converted to String. When this variable changes, the table will be reloaded with the new filter value.
 *        b) element: ReactNode that will be rendered as the filter element for this column.
 *    - Custom filter elements can be provided for each column.
 * @param initialData (optional): Array of objects that will be used as initial data to populate the table.
 * @param fetchData (optional): Function to fetch data from the server.
 *   If not provided the table will use always the initial data provided in initialData. The function must support 3 parameters (page, pageSize, filters)
 *   input parameters:
 *    - page: The current page number (1-indexed).
 *    - pageSize: The number of items per page.
 *    - filters: An object containing the current filters.
 *   return: an object with two properties:
 *    - data: The data to be displayed in the table.
 *    - totalPages: The total number of pages available.
 * @param pageSize - Number of items per page (default is 10).
 * Usage:
 * <DataTable
 *   columns={columns}
 *   fetchData={fetchDataFunction}
 *   pageSize={20}
 * />
 */

import { useState, useEffect } from "react";
import {
  Table,
  Pagination,
  IconButton,
  Icon,
  HStack,
  VStack,
} from "@chakra-ui/react";
import { Input, ButtonGroup } from "@chakra-ui/react";
import { LuChevronLeft, LuChevronRight } from "react-icons/lu";
import { Tooltip } from "../ui/tooltip";
import { useNavigate } from "react-router-dom";
import ErrorAlert from "../ErrorAlert";
import { LoadingOverlay } from "../SpinnerOverlay";

interface CustomFilterProps {
  stateVariable: any;
  element: React.ReactNode;
}

/* Definition of the table column properties */
interface ColumnProps<T> {
  header: string;
  accessor: keyof T;
  dateFormatter?: (value: Date | null) => string | null;
  filterable?: boolean | CustomFilterProps;
}

export enum RowActionResult {
  NONE = 0,
  REMOVE = 1,
  ADD = 2,
  RELOAD_PAGE = 3,
}
/** Definition of an  action that can be performed on each row of the table. */
interface RowAction<T> {
  action: (
    rowItem: T,
    navigate: ReturnType<typeof useNavigate>
  ) => Promise<RowActionResult>;
  title: string;
  icon?: React.ReactNode;
  tooltip?: string;
}

/** Definition of the fetch data function result type */
interface FetchResult<T> {
  data: T[];
  totalPages: number;
}

interface DataTableProps<T> {
  columns: ColumnProps<T>[];
  height?: string; // Optional prop to set the height of the table
  minHeight?: string; // Optional prop to set the minimum height of the table
  maxHeight?: string; // Optional prop to set the maximum height of the table
  /* Definition of the Fetch Data function. This function is invoked when the contents of the table are to be fetched from the server. */
  fetchData?: (params: {
    page: number;
    pageSize: number;
    filters: Partial<Record<keyof T, string>>;
  }) => Promise<FetchResult<T>>;
  pageSize?: number;
  /* Definition of the Row View function. This function is invoked when the "view" icon of each row is clicked to view the details of the row. */
  rowActions?: RowAction<T>[];
  initialData?: T[];
  title?: string;
  forceRefresh?: number; // Optional prop to trigger a refresh
}

/*
 * Formats a value as a string for display in the table.
 * Handles null, undefined, string, number, and Date types.
 */
function formatMemberAsString(
  object: any,
  accessor: string,
  dateFormatter?: (value: Date | null) => string | null
): string {
  const value = object[accessor];
  if (value === null || value === undefined) {
    return "";
  }
  if (typeof value === "function") {
    return object[accessor]();
  } else if (typeof value === "string") {
    return value;
  } else if (typeof value === "number") {
    return value.toString();
  } else if (value instanceof Date) {
    if (dateFormatter) {
      return dateFormatter(value) || "";
    } else {
      return value.toLocaleDateString(undefined, {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
      });
    }
  }
  return String(value);
}

/*
 * DataTable component that displays a paginated and filterable table.
 * It accepts columns definitions, a fetch function to retrieve data, and an optional page size.
 */
function DataTable<T extends Record<string, any>>({
  columns,
  height = "",
  minHeight = "",
  maxHeight = "",
  fetchData,
  pageSize = 10,
  rowActions = [],
  initialData,
  title,
  forceRefresh = 0, // Optional prop to trigger a refresh
}: DataTableProps<T>) {
  const [data, setData] = useState<T[]>(initialData || []);
  const [filters, setFilters] = useState<Partial<Record<keyof T, string>>>({});
  const [page, setPage] = useState<number>(1);
  const [totalPages, setTotalPages] = useState<number>(1);
  const [loading, setLoading] = useState<boolean>(false);
  const [fetchError, setFetchError] = useState<string | null>(null);
  const navigate = useNavigate();

  const handleFilterChange = (key: keyof T, value: string) => {
    setFilters((prev) => ({ ...prev, [key]: value }));
    setPage(1);
  };

  /*
    If no fetchData function is provided, this function filters the initial data locally based on the current filters and pagination.
  */
  const filterLocal = ({
    page,
    pageSize,
    filters,
  }: {
    page: number;
    pageSize: number;
    filters: Partial<Record<keyof T, string>>;
  }): FetchResult<T> => {
    // Filter the data based on the filters
    const sourceList = initialData || [];
    let filteredData = sourceList;
    Object.entries(filters).forEach(([key, value]) => {
      if (value) {
        filteredData = filteredData.filter((elem) =>
          formatMemberAsString(elem, key)
            .toString()
            .toLowerCase()
            .includes(value.toLowerCase())
        );
      }
    });

    // slice the data for pagination
    const start = (page - 1) * pageSize;
    const end = start + pageSize;
    filteredData = filteredData.slice(start, end);

    // Create a response object of type FetchResult (the type expected by the DataTable)
    const retValue: FetchResult<T> = {
      data: filteredData,
      totalPages: Math.ceil(sourceList.length / pageSize),
    };
    return retValue;
  };

  const loadData = async () => {
    setLoading(true);
    let result;
    if (fetchData) {
      try {
        result = await fetchData({ page, pageSize, filters });
        setFetchError(null); // Clear any previous errors
      } catch (error) {
        setData([]);
        setTotalPages(0);
        setLoading(false);
        setFetchError(
          error instanceof Error
            ? error.message
            : "An error occurred while fetching data"
        );
        console.error("Error fetching data:", error);
        return;
      }
    } else {
      result = filterLocal({ page, pageSize, filters });
    }
    setData(result.data);
    setTotalPages(result.totalPages);
    setLoading(false);
  };

  useEffect(() => {
    loadData();
  }, [page, forceRefresh]);

  useEffect(() => {
    const delayedHandler = setTimeout(() => {
      loadData();
    }, 500);

    return () => {
      clearTimeout(delayedHandler);
    };
  }, [filters]);

  useEffect(() => {
    if (initialData !== undefined) {
      // If initialData is provided, set it as the initial data
      setData(initialData || []);
    }
  }, [initialData]);

  const processRowAction = async (
    action: RowAction<T>,
    rowData: T,
    navigate: ReturnType<typeof useNavigate>
  ) => {
    const result = await action.action(rowData, navigate);
    if (result === RowActionResult.RELOAD_PAGE) {
      loadData();
    }
  };

  // Handle filter changes for columns that have a custom filterable object.
  let numFilterableColumns = 0;
  columns.forEach((col) => {
    numFilterableColumns +=
      col.filterable == true || col.filterable !== false ? 1 : 0;
    if (col.filterable && typeof col.filterable === "object") {
      const stateVariable = col.filterable.stateVariable;
      useEffect(() => {
        handleFilterChange(col.accessor, `${stateVariable}`);
      }, [col.filterable.stateVariable]);
    }
  });

  return (
    <>
      <VStack align="stretch">
        {numFilterableColumns > 0 && (
          <HStack>
            {columns.map(
              (col) =>
                col.filterable &&
                (typeof col.filterable === "boolean" ? (
                  <Input
                    key={String(col.accessor)}
                    maxW={"30ch"}
                    placeholder={`Filter by ${col.header}`}
                    value={filters[col.accessor] || ""}
                    onChange={(e) =>
                      handleFilterChange(col.accessor, e.target.value)
                    }
                    mt={1}
                  />
                ) : (
                  // Custom filter element
                  <div key={String(col.accessor)}>{col.filterable.element}</div>
                ))
            )}
          </HStack>
        )}
        <Table.ScrollArea
          borderWidth="5px"
          rounded="sm"
          maxW="100%"
          height={height}
          minHeight={minHeight}
          maxHeight={maxHeight}
        >
          <LoadingOverlay show={loading}>
            <Table.Root stickyHeader size="sm" showColumnBorder striped>
              <Table.Header>
                {title && (
                  <Table.Row bg="bg.emphasized" color="text.primary">
                    <Table.ColumnHeader
                      textAlign="center"
                      fontWeight="bold"
                      fontSize="lg"
                      colSpan={columns.length + rowActions.length}
                    >
                      {title}
                    </Table.ColumnHeader>
                  </Table.Row>
                )}
                <Table.Row bg="bg.emphasized" color="text.primary">
                  {columns.map((col) => (
                    <Table.ColumnHeader
                      key={String(col.accessor)}
                      fontWeight="bold"
                    >
                      {col.header}
                    </Table.ColumnHeader>
                  ))}
                  {rowActions.map((action, index) => (
                    <Table.ColumnHeader key={index} textAlign="center">
                      {action.title || ""}
                    </Table.ColumnHeader>
                  ))}
                </Table.Row>
              </Table.Header>
              {
                // TABLE BODY
              }
              <Table.Body>
                {fetchError ? (
                  <Table.Row>
                    <Table.Cell
                      colSpan={columns.length + rowActions.length}
                      textAlign="center"
                    >
                      <ErrorAlert errorMessage={fetchError} />
                    </Table.Cell>
                  </Table.Row>
                ) : data.length === 0 ? (
                  <></>
                ) : (
                  data.map((rowData, i) => (
                    <Table.Row key={i}>
                      {columns.map((col) => (
                        <Table.Cell key={String(col.accessor)}>
                          {formatMemberAsString(
                            rowData,
                            String(col.accessor),
                            col.dateFormatter
                          )}
                        </Table.Cell>
                      ))}
                      {rowActions.map((action, index) => (
                        <Table.Cell key={index} textAlign="center">
                          <Tooltip content={action.tooltip}>
                            {action.icon && (
                              <Icon
                                size="lg"
                                cursor="pointer"
                                onClick={() =>
                                  processRowAction(action, rowData, navigate)
                                }
                              >
                                {action.icon}
                              </Icon>
                            )}
                          </Tooltip>
                        </Table.Cell>
                      ))}
                    </Table.Row>
                  ))
                )}
              </Table.Body>
            </Table.Root>
          </LoadingOverlay>
        </Table.ScrollArea>
      </VStack>
      {
        // PAGINATION
      }
      {totalPages > 1 && (
        <Pagination.Root
          count={totalPages * pageSize}
          pageSize={pageSize}
          page={page}
        >
          <ButtonGroup variant="ghost" size="sm" wrap="wrap">
            <Pagination.PrevTrigger asChild>
              <IconButton onClick={() => setPage((p) => Math.max(p - 1, 1))}>
                <LuChevronLeft />
              </IconButton>
            </Pagination.PrevTrigger>

            <Pagination.Items
              render={(page) => (
                <IconButton
                  variant={{ base: "ghost", _selected: "outline" }}
                  onClick={() => setPage(page.value)}
                >
                  {page.value}
                </IconButton>
              )}
            />

            <Pagination.NextTrigger asChild>
              <IconButton
                onClick={() => setPage((p) => Math.min(p + 1, totalPages))}
              >
                <LuChevronRight />
              </IconButton>
            </Pagination.NextTrigger>
          </ButtonGroup>
        </Pagination.Root>
      )}
    </>
  );
}

export default DataTable;
export type { ColumnProps, RowAction, DataTableProps, FetchResult };
