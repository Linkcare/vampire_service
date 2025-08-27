import DataTable, {
  ColumnProps,
  FetchResult,
  RowAction,
  RowActionResult,
} from "./DataTable/DataTable";
import { Shipment, ShipmentStatus } from "../types/ShipmentTypes";
import { dateFormat } from "../helpers/Helpers";
import { LuEye } from "react-icons/lu";
import { useNavigate } from "react-router-dom";
import { loadShipmentList } from "../services/VampireService/VampireService";
import { useState } from "react";
import SelectLab from "./SelectLab";

async function viewShipment(
  rowItem: Shipment,
  navigate: ReturnType<typeof useNavigate>
): Promise<RowActionResult> {
  if (rowItem.statusId === ShipmentStatus.PREPARING) {
    navigate(`/new/${rowItem.id}`);
  } else if (rowItem.statusId === ShipmentStatus.RECEIVING) {
    navigate(`/receive/${rowItem.id}`);
  } else {
    navigate(`/shipment/${rowItem.id}`);
  }

  return RowActionResult.NONE;
}

let rowListActions: RowAction<Shipment>[] = [
  {
    action: viewShipment,
    title: "",
    icon: <LuEye />,
    tooltip: "View shipment details",
  },
];

function ShipmentsTable() {
  // Function to fetch shipments data from the server
  const fetchShipments = async ({
    page,
    pageSize,
    filters,
  }: {
    page: number;
    pageSize: number;
    filters: Partial<Record<keyof Shipment, string>>;
  }): Promise<FetchResult<Shipment>> => {
    // REST call to fetch shipments
    const response = await loadShipmentList(page, pageSize, filters);

    // Create a response object of type FetchResult (the type expected by the DataTable)
    const retValue: FetchResult<Shipment> = {
      data: response.rows,
      totalPages: Math.ceil(response.total_count / pageSize),
    };
    return retValue;
  };

  const [toLabFilter, setToLabFilter] = useState("");

  const columns: ColumnProps<Shipment>[] = [
    {
      header: "ID",
      accessor: "ref",
      filterable: true,
    },
    {
      header: "From",
      accessor: "sentFrom",
      filterable: false,
    },
    {
      header: "To",
      accessor: "sentTo",
      filterable: {
        stateVariable: toLabFilter,
        element: (
          <SelectLab
            value={`${toLabFilter}`}
            placeholder="To lab"
            handleChange={setToLabFilter}
          />
        ),
      },
    },
    {
      header: "Date",
      accessor: "sendDate",
      filterable: false,
      dateFormatter: dateFormat,
    },
    { header: "Sent by", accessor: "sender", filterable: false },
    {
      header: "Reception date",
      accessor: "receptionDate",
      filterable: false,
      dateFormatter: dateFormat,
    },
    { header: "Received by", accessor: "receiver", filterable: false },
    { header: "Status", accessor: "getStatusStr", filterable: false },
  ];

  return (
    <>
      <DataTable<Shipment>
        columns={columns}
        title="List of Shipments"
        fetchData={fetchShipments}
        pageSize={5}
        rowActions={rowListActions}
      ></DataTable>
    </>
  );
}

export default ShipmentsTable;
