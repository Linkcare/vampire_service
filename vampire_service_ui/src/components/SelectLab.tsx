/*
 <Select> component with a list of teams that can be selected for a lab shipment.
 The lab that corresponds to the current session's team ID is excluded from the list.
*/
import { NativeSelect } from "@chakra-ui/react";
import { useEffect, useState } from "react";
import { shipmentLocations } from "../services/VampireService/VampireService";
import { ShipmentLocation } from "../types/ShipmentTypes";

function SelectLab({
  value,
  placeholder = "",
  bg = "",
  initialOption,
  handleChange,
  excludeLocation = "",
}: {
  value?: string | null;
  placeholder?: string;
  bg?: string;
  initialOption?: string | null;
  handleChange?: (value: any) => void;
  excludeLocation?: string;
}) {
  const [locations, setLocations] = useState<ShipmentLocation[]>([]);

  const loadLocations = async () => {
    try {
      const response = await shipmentLocations();
      const teamList = response.filter((location: ShipmentLocation) => {
        // Filter teams based on the session's team ID
        return !excludeLocation || location.id?.toString() !== excludeLocation;
      });
      setLocations(teamList);
    } catch (error) {
      setLocations([]);
    }
  };

  useEffect(() => {
    loadLocations();
  }, []);

  return (
    <NativeSelect.Root size="sm" width="240px" variant="subtle">
      <NativeSelect.Field
        bg={bg}
        defaultValue={!value && initialOption ? initialOption : undefined}
        value={value || ""}
        placeholder={placeholder}
        onChange={(e) => {
          if (handleChange) {
            handleChange(e.target.value);
          }
        }}
      >
        {locations.map((location) => (
          <option key={location.id?.toString()} value={location.id?.toString()}>
            {location.name}
          </option>
        ))}
      </NativeSelect.Field>
      <NativeSelect.Indicator />
    </NativeSelect.Root>
  );
}

export default SelectLab;
