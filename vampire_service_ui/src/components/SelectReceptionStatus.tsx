/*
 <Select> component with a list of teams that can be selected for a lab shipment.
 The lab that corresponds to the current session's team ID is excluded from the list.
*/
import { NativeSelect } from "@chakra-ui/react";
import React from "react";
import { ShipmentReceptionStatus } from "../types/ShipmentTypes";

interface ReceptionStatusProps {
  id: string;
  name: string | React.ReactNode;
  color?: string;
}
function SelectReceptionStatus({
  value,
  placeholder = "",
  bg = "",
  initialOption,
  handleChange,
}: {
  value?: string | null;
  placeholder?: string;
  bg?: string;
  initialOption?: string | null;
  handleChange?: (value: any) => void;
  excludeLocation?: string;
}) {
  const packageStatus: ReceptionStatusProps[] = [
    {
      id: ShipmentReceptionStatus.ALL_GOOD,
      name: "All aliquots in good state",
      color: "green",
    },
    {
      id: ShipmentReceptionStatus.PARTIALLY_BAD,
      name: "Some aliquots damaged or missing",
      color: "orange",
    },
    {
      id: ShipmentReceptionStatus.ALL_BAD,
      name: "All aliquots damaged",
      color: "red",
    },
  ];

  let textColor = undefined;
  packageStatus.forEach((st) => {
    if (st.id === value) {
      textColor = st.color || "";
    }
  });

  return (
    <NativeSelect.Root size="sm" width="240px" variant="subtle">
      <NativeSelect.Field
        bg={bg}
        defaultValue={!value && initialOption ? initialOption : undefined}
        value={value || ""}
        color={textColor}
        placeholder={placeholder}
        onChange={(e) => {
          if (handleChange) {
            handleChange(e.target.value);
          }
        }}
      >
        {packageStatus.map((st) => (
          <option key={st.id} value={st.id} color={st.color}>
            {st.name}
          </option>
        ))}
      </NativeSelect.Field>
      <NativeSelect.Indicator />
    </NativeSelect.Root>
  );
}

export default SelectReceptionStatus;
