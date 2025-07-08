import "../Styles.css";
import { useNavigate, useParams } from "react-router-dom";
import PageHeader from "../components/PageBuilder/PageHeader";
import {
  Box,
  VStack,
  ActionBar,
  Button,
  HStack,
  Input,
  Textarea,
  Dialog,
  Portal,
  CloseButton,
} from "@chakra-ui/react";
import { useEffect, useRef, useState } from "react";
import SectionBlock from "../components/PageBuilder/SectionBlock";
import ProtectedRoute from "../components/ProtectedRoute";
import {
  Aliquot,
  Shipment,
  ShipmentReceptionStatus,
} from "../types/ShipmentTypes";
import { loadShipmentDetails } from "../services/VampireService/VampireService";
import SpinnerOverlay from "../components/SpinnerOverlay";
import ErrorAlert from "../components/ErrorAlert";
import { useSession } from "../components/SessionContext";
import { LuPackageOpen } from "react-icons/lu";
import ShipmentSenderDetails from "../components/ShipmentSenderDetails";
import LabelInputPair from "../components/PageBuilder/LabelInputPair";
import { FormFieldsColors } from "../components/PageBuilder/Styles";
import {
  composeDatetime,
  dateFormat,
  isValidDate,
  isValidTime,
} from "../helpers/Helpers";
import SelectReceptionStatus from "../components/SelectReceptionStatus";
import * as VampireService from "../services/VampireService/VampireService";
import DataTable, {
  ColumnProps,
  RowAction,
  RowActionResult,
} from "../components/DataTable/DataTable";
import { TbTrashX } from "react-icons/tb";
import SelectAliquotCondition from "../components/SelectAliquotCondition";

function ShipmentReceptionView() {
  const { session } = useSession();
  const navigate = useNavigate();
  const { id } = useParams();
  const shipmentId = Number(id);
  const [receptionDate, setReceptionDate] = useState<string | null>(null);
  const [receptionTime, setReceptionTime] = useState<string | null>(null);
  const [receptionStatusId, setReceptionStatusId] = useState<string | null>(
    null
  );
  const [receptionComments, setReceptionComments] = useState<string>("");
  const [shipment, setShipment] = useState<Shipment | null>(null);
  const [loaded, setLoaded] = useState<boolean>(false);
  const [fetchError, setFetchError] = useState<string | null>(null);
  const [isReady, setIsReady] = useState<boolean>(false);
  const [sending, setSending] = useState<boolean>(false);
  const isFirstRender = useRef(true);
  const [operationError, setOperationError] = useState<string | null>(null);
  const [rowActions, setRowActions] = useState<RowAction<Aliquot>[]>([]);
  const [columns, setColumns] = useState<ColumnProps<Aliquot>[]>([]);
  // If an aliquot is selected to mark its condition, a modal dialog will be displayed to select the condition of the aliquot.
  const [aliquotForModal, setAliquotForModal] = useState<Aliquot | null>(null);
  const [forceRefresh, setForceRefresh] = useState(0);

  /*
   * Function to mark the condition of an aliquot.
   * This function displays a modal dialog to select the condition of the aliquot.
   */
  const showAliquotConditionDialog = async (
    row: string | Aliquot
  ): Promise<RowActionResult> => {
    if (shipment) {
      const aliquotId = typeof row === "string" ? row : row.id;
      const selected = shipment.aliquots?.find((a) => a.id === aliquotId);
      if (selected) {
        // use a copy of the selected aliquot to avoid modifying the original object
        setAliquotForModal({ ...selected } as Aliquot);
      }
    }

    return RowActionResult.NONE;
  };

  /* Columns that will be displayed by default in the table of aliquots included in the shipment.
  This is the base set of columns that will be displayed in the table, but it can be extended depending on the reception status of the shipment.
  */
  const baseColumns: ColumnProps<Aliquot>[] = [
    { header: "ID", accessor: "id", filterable: true },
    { header: "Patient", accessor: "patientRef", filterable: true },
    { header: "Type", accessor: "type", filterable: true },
  ];
  const aliquotConditionColumn: ColumnProps<Aliquot> = {
    header: "Condition",
    accessor: "getConditionStr",
    filterable: false,
  };

  // Action icon to display en each row of the table to mark the condition of the aliquot.
  const markAliquotCondtionAction: RowAction<Aliquot> = {
    action: showAliquotConditionDialog,
    title: "",
    icon: <TbTrashX />,
    tooltip: "Add to shipment",
  };

  const loadShipment = async () => {
    // REST call to fetch shipments
    let response: Shipment | null = null;
    try {
      response = await loadShipmentDetails(shipmentId);
      setFetchError(null);
    } catch (error) {
      setShipment(null);
      setLoaded(true);
      setFetchError(
        `Error loading shipment details: ${
          error instanceof Error ? error.message : "Unknown error"
        }`
      );
      return;
    }

    setShipment(response);
    setReceptionDate(dateFormat(response?.receptionDate, "dd/MM/yyyy"));
    setReceptionTime(dateFormat(response?.receptionDate, "HH:mm"));
    setReceptionStatusId(response?.receptionStatusId || null);
    setReceptionComments(response?.receptionComments || "");
    setLoaded(true);

    // Setup the table columns and actions
    const tableColumns: ColumnProps<Aliquot>[] = baseColumns;
    const tableActions: RowAction<Aliquot>[] = [];

    if (response.receptionStatusId === ShipmentReceptionStatus.PARTIALLY_BAD) {
      // If the shipment is partially bad, we can mark the condition of individual aliquots from the shipment
      tableColumns.push(aliquotConditionColumn);
      tableActions.push(markAliquotCondtionAction);
    }
    setColumns(tableColumns);
    setRowActions(tableActions);
  };

  // Load the shipment data when the component mounts
  useEffect(() => {
    loadShipment();
  }, []);

  const updateShipment = async () => {
    if (isFirstRender.current) {
      // Skip the first render to avoid updating the shipment object with empty values
      isFirstRender.current = false;
      return;
    }
    if (!shipment) {
      return;
    }

    shipment.id = shipmentId;
    shipment.receiverId = session?.userId || null;
    shipment.receptionDate = composeDatetime(receptionDate, receptionTime);
    shipment.receptionStatusId = receptionStatusId;
    shipment.receptionComments = receptionComments;

    setOperationError("");
    try {
      await VampireService.updateShipment(shipment);
    } catch (error) {
      if (error instanceof Error) {
        setOperationError(error.message);
      } else {
        setOperationError(String(error));
      }
    }
  };

  useEffect(() => {
    /* Update the shipment object whenever the receptionDate, receptionTime, receptionComments.
     * We use a timeout to avoid updating the shipment object too frequently while the user is typing.
     */
    const delayedHandler = setTimeout(() => {
      updateShipment();

      if (
        shipment?.receptionStatusId !== ShipmentReceptionStatus.PARTIALLY_BAD
      ) {
        setColumns(baseColumns);
        setRowActions([]);
      } else {
        setColumns([...baseColumns, aliquotConditionColumn]);
        setRowActions([markAliquotCondtionAction]);
      }
    }, 1000);

    return () => {
      clearTimeout(delayedHandler);
    };
  }, [receptionDate, receptionTime, receptionComments]);

  useEffect(() => {
    /* Update the shipment object whenever the shipmentRef or destLabId change.
     * It is only necessary to perform an update when the shipmentRef or destLabId change, because the aliquots are updated automatically when included or excluded from the shipment.     * This ensures that the shipment object always has the latest values.
     * We use a timeout to avoid updating the shipment object too frequently while the user is typing.
     */
    updateShipment().then(() => {
      loadShipment(); // After changing the reception status, we need to reload the shipment to update the columns and actions in the table.
    });
  }, [receptionStatusId]);

  useEffect(() => {
    // Check if the shipment is ready to be sent
    const allow =
      isValidDate(receptionDate) &&
      isValidTime(receptionTime) &&
      (receptionStatusId === ShipmentReceptionStatus.ALL_GOOD ||
        (receptionStatusId !== null && receptionComments.trim() !== ""));
    setIsReady(allow);
  }, [receptionDate, receptionTime, receptionStatusId, receptionComments]);

  const updateReceptionDatetime = (
    date: string | null,
    time: string | null
  ) => {
    if (date !== receptionDate) {
      setReceptionDate(date);
    }
    if (date !== receptionTime) {
      setReceptionTime(time);
    }
  };

  const markAliquotCondition = async (aliquot: Aliquot) => {
    if (!aliquot) {
      setOperationError("No aliquot selected.");
      return;
    }
    setAliquotForModal(null);
    try {
      await VampireService.markAliquotCondition(
        shipmentId,
        aliquot.id || "",
        aliquot.conditionId
      );
      const selected = shipment?.aliquots?.find((a) => a.id === aliquot.id);
      if (selected) {
        /* The list of aliquots displayed in the table is in memory and never refreshed from the server, 
        so we need to update the condition of the selected aliquot in the list. */
        selected.conditionId = aliquot.conditionId;
        setForceRefresh((prev) => prev + 1); // Force a refresh of the table
      }
    } catch (error) {
      if (error instanceof Error) {
        setOperationError(error.message);
      } else {
        setOperationError(String(error));
      }
    }
  };

  const finishReceptionProcess = async () => {
    if (!shipment) {
      setOperationError("Shipment data is not loaded.");
      return;
    }

    shipment.id = shipmentId || null;
    shipment.receiverId = session?.userId || null;
    shipment.receptionDate = composeDatetime(receptionDate, receptionTime);
    shipment.receptionStatusId = receptionStatusId;
    shipment.receptionComments = receptionComments;

    setOperationError(null);
    setSending(true);
    try {
      await VampireService.ackShipmentReception(shipment);
      navigate("/");
    } catch (error) {
      if (error instanceof Error) {
        setOperationError(error.message);
      } else {
        setOperationError(String(error));
      }
    } finally {
      setSending(false);
    }
  };

  return (
    <ProtectedRoute>
      <PageHeader>{`Reception of shipment: ${shipment?.ref}`}</PageHeader>
      {loaded && shipment ? (
        <>
          <Box p={4} borderWidth={1} borderRadius="md" mb={4}>
            <VStack gap={4} align="stretch">
              {operationError && <ErrorAlert errorMessage={operationError} />}
              <ShipmentSenderDetails shipment={shipment} />
              <SectionBlock title="Reception details">
                <VStack width="100%" align="stretch" gap={4}>
                  <HStack>
                    <LabelInputPair label="Received by:">
                      {session?.name}
                    </LabelInputPair>{" "}
                    <LabelInputPair label="Reception date:">
                      <Input
                        value={receptionDate || ""}
                        placeholder="DD/MM/YYYY"
                        bg={
                          isValidDate(receptionDate)
                            ? FormFieldsColors.Default
                            : FormFieldsColors.Mandatory
                        }
                        onChange={(e) => {
                          updateReceptionDatetime(
                            e.target.value,
                            receptionTime
                          );
                        }}
                        width="30ch"
                      />
                    </LabelInputPair>
                    <LabelInputPair label="Time:">
                      <Input
                        value={receptionTime || ""}
                        placeholder="hh:mm"
                        bg={
                          isValidTime(receptionTime)
                            ? FormFieldsColors.Default
                            : FormFieldsColors.Mandatory
                        }
                        onChange={(e) => {
                          updateReceptionDatetime(
                            receptionDate,
                            e.target.value
                          );
                        }}
                        width="30ch"
                      />
                    </LabelInputPair>
                    <LabelInputPair label="Status:">
                      <SelectReceptionStatus
                        value={receptionStatusId}
                        placeholder="Indicate shipment status"
                        handleChange={setReceptionStatusId}
                        bg={
                          receptionStatusId
                            ? FormFieldsColors.Default
                            : FormFieldsColors.Mandatory
                        }
                      />
                    </LabelInputPair>
                  </HStack>
                  <LabelInputPair label="Comments abot the status of the shipment on reception:">
                    <Textarea
                      value={receptionComments}
                      bg={
                        receptionStatusId ===
                          ShipmentReceptionStatus.ALL_GOOD ||
                        (receptionStatusId !== null &&
                          receptionComments.trim() !== "")
                          ? FormFieldsColors.Default
                          : FormFieldsColors.Mandatory
                      }
                      onChange={(e) => {
                        setReceptionComments(e.target.value);
                      }}
                    ></Textarea>
                  </LabelInputPair>
                </VStack>
              </SectionBlock>
              <SectionBlock title="Shipment contents">
                <DataTable<Aliquot>
                  columns={columns}
                  rowActions={rowActions}
                  pageSize={5}
                  initialData={shipment.aliquots}
                  forceRefresh={forceRefresh}
                ></DataTable>
              </SectionBlock>
            </VStack>
          </Box>

          {
            // MODAL DIALOG for selecting the condition of an aliquot
            aliquotForModal && (
              <Dialog.Root defaultOpen={true}>
                <Portal>
                  <Dialog.Backdrop />
                  <Dialog.Positioner>
                    <Dialog.Content>
                      <Dialog.Header>
                        <Dialog.Title>Dialog Title</Dialog.Title>
                      </Dialog.Header>
                      <Dialog.Body>
                        <p>
                          Indicate the condition of the aliquot{" "}
                          {aliquotForModal.id}
                        </p>
                        <SelectAliquotCondition
                          initialOption={aliquotForModal.conditionId}
                          handleChange={(value) => {
                            if (aliquotForModal) {
                              aliquotForModal.conditionId = value;
                            }
                          }}
                        />
                      </Dialog.Body>
                      <Dialog.Footer>
                        <Dialog.ActionTrigger asChild>
                          <Button
                            variant="outline"
                            onClick={() => {
                              setAliquotForModal(null);
                            }}
                          >
                            Cancel
                          </Button>
                        </Dialog.ActionTrigger>
                        <Button
                          onClick={() => {
                            markAliquotCondition(aliquotForModal);
                          }}
                        >
                          Save
                        </Button>
                      </Dialog.Footer>
                      <Dialog.CloseTrigger asChild>
                        <CloseButton
                          size="sm"
                          onClick={() => {
                            setAliquotForModal(null);
                          }}
                        />
                      </Dialog.CloseTrigger>
                    </Dialog.Content>
                  </Dialog.Positioner>
                </Portal>
              </Dialog.Root>
            )
          }

          {
            // ACTION BAR
            session?.can("receive_shipments") &&
              shipment.sentToId == session.teamId && (
                <ActionBar.Root open={true}>
                  <ActionBar.Positioner>
                    <ActionBar.Content>
                      <Dialog.Root placement="center">
                        <Dialog.Trigger asChild>
                          <Button
                            disabled={!isReady}
                            variant="outline"
                            size="2xl"
                            colorPalette={isReady ? "green" : "red"}
                          >
                            <LuPackageOpen />
                            Finish reception
                          </Button>
                        </Dialog.Trigger>

                        <Portal>
                          <Dialog.Backdrop />
                          <Dialog.Positioner>
                            <Dialog.Content>
                              <Dialog.Header>
                                <Dialog.Title>
                                  Finish reception process
                                </Dialog.Title>
                              </Dialog.Header>
                              <Dialog.Body>
                                <Dialog.Description>
                                  Do you want to complete the reception process
                                  of shipment <strong>{shipment.ref}</strong>?
                                </Dialog.Description>
                              </Dialog.Body>
                              <Dialog.Footer>
                                <Dialog.ActionTrigger asChild>
                                  <Button variant="outline">Cancel</Button>
                                </Dialog.ActionTrigger>
                                <Dialog.ActionTrigger asChild>
                                  <Button
                                    colorPalette="green"
                                    onClick={() => {
                                      finishReceptionProcess();
                                    }}
                                  >
                                    Confirm
                                  </Button>
                                </Dialog.ActionTrigger>
                              </Dialog.Footer>
                            </Dialog.Content>
                          </Dialog.Positioner>
                        </Portal>
                      </Dialog.Root>
                    </ActionBar.Content>
                  </ActionBar.Positioner>
                </ActionBar.Root>
              )
          }
        </>
      ) : fetchError ? (
        <ErrorAlert errorMessage={fetchError} />
      ) : (
        sending && <SpinnerOverlay />
      )}
    </ProtectedRoute>
  );
}

export default ShipmentReceptionView;
