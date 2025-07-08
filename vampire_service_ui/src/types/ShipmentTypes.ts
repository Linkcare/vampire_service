import { dateFormat } from "../helpers/Helpers";

export enum ShipmentStatus {
  PREPARING = "PREPARING",
  SENT = "SHIPPED",
  RECEIVING = "RECEIVING",
  RECEIVED = "RECEIVED",
}

export enum AliquotConditions {
  NORMAL = "NO_DAMAGE",
  WHOLE_DAMAGE = "WHOLE_DAMAGE",
  BROKEN = "BROKEN",
  MISSING = "MISSING",
  DEFROST = "DEFROST",
  EXOSOMES_FAILURE = "EXOSOMES_FAILURE",
  OTHER = "OTHER",
}

export enum ShipmentReceptionStatus {
  ALL_GOOD = "ALL_GOOD",
  PARTIALLY_BAD = "PARTIALLY_BAD",
  ALL_BAD = "ALL_BAD",
}

export class ShipmentLocation {
  public id: number | null = null; // Unique identifier for the location (Team ID)
  public code: string | null = null; // Team Code
  public name: string | null = null; // Name of the location (e.g., Team name)

  static fromJSON(data: any): ShipmentLocation {
    if (!data) {
      throw new Error("No location data received");
    }
    const location = new ShipmentLocation();
    location.id = Number(data.id) || null;
    location.code = data.code || null;
    location.name = data.name || null;

    return location;
  }
}

export class Aliquot {
  public id: string | null = null; // Unique identifier for the aliquot
  public patientRef: string | null = null; // ID of the patient
  public type: string | null = null; // Type of the aliquot (e.g., serum, plasma, whole blood)
  public locationId: number | null = null; // Current location of the aliquot (reference of the Team which has the aliquot)
  public location: string | null = null; // Name of the current location of the aliquot (e.g., Team name)
  public statusId: string | null = null; // Status of the aliquot (e.g., ok, Rejected, Used)
  public created: Date | null = null; // Date of the last update of the aliquot
  public lastUpdate: Date | null = null; // Date of creation
  public shipmentId: boolean = false; // Indicates if the aliquot is assigned to a shipment
  public conditionId: string | null = null; // Condition of the aliquot (e.g. Normal, Broken, Defrost)
  public selected: boolean = false; // Indicates if the aliquot is selected in a list (e.g. for shipment)

  /**
   *
   * @returns A string representation of the condition of the aliquot.
   */
  public getConditionStr(): string {
    if (this.conditionId === AliquotConditions.NORMAL || !this.conditionId) {
      return "Normal";
    } else if (this.conditionId === AliquotConditions.WHOLE_DAMAGE) {
      return "Whole shipment damaged";
    } else if (this.conditionId === AliquotConditions.BROKEN) {
      return "Broken";
    } else if (this.conditionId === AliquotConditions.MISSING) {
      return "Missing";
    } else if (this.conditionId === AliquotConditions.DEFROST) {
      return "Defrosted";
    } else if (this.conditionId === AliquotConditions.EXOSOMES_FAILURE) {
      return "Exosomes extraction failed";
    } else {
      return this.conditionId;
    }
  }

  static fromJSON(data: any): Aliquot {
    if (!data) {
      throw new Error("No aliquot data received");
    }
    const aliquot = new Aliquot();
    aliquot.id = data.id || null;
    aliquot.patientRef = data.patientRef || null;
    aliquot.type = data.type || null;
    aliquot.locationId = data.locationId || null;
    aliquot.location = data.location || null;
    aliquot.statusId = data.statusId || null;
    aliquot.conditionId = data.conditionId || null;
    aliquot.created = data.created ? new Date(data.created) : null;
    aliquot.lastUpdate = data.lastUpdate ? new Date(data.lastUpdate) : null;

    return aliquot;
  }

  public toJSON(): any {
    return {
      id: this.id,
      patientRef: this.patientRef,
      type: this.type,
      locationId: this.locationId,
      location: this.location,
      statusId: this.statusId,
      conditionId: this.conditionId,
      created: dateFormat(this.created),
      lastUpdate: dateFormat(this.lastUpdate),
      selected: this.selected,
    };
  }
}

export class Shipment {
  public id: number | null = null; // Unique identifier for the shipment
  public ref: string | null = null; // Unique custom identifier for the shipment
  public statusId: string | null = null; // Status of the reception (e.g., received, pending)
  public sentFromId: number | null = null; // ID of the source Team
  public sentFrom: string | null = null; // Name of the source Team
  public sentToId: number | null = null; // ID of the destination Team
  public sentTo: string | null = null; // Name of the destination Team
  public sendDate: Date | null = null; // Date when the shipment was sent
  public senderId: number | null = null; // ID of the user who created the shipment
  public sender: string | null = null; // Name of the user who created the shipment
  public receptionDate: Date | null = null; // Date when the shipment was received
  public receiverId: number | null = null; // ID of the user who received the shipment
  public receiver: string | null = null; // Name of the user who received the shipment
  public receptionStatusId: string | null = null; // ID of the status of the shipment on reception (e.g., OK, Bad...)
  public receptionComments: string | null = null; // Comments about the status of the shipment on reception
  public aliquots: Aliquot[] = []; // List of aliquots included in the shipment

  /**
   *
   * @returns A string representation of the condition of the aliquot.
   */
  public getStatusStr(): string {
    if (this.statusId === ShipmentStatus.PREPARING || !this.statusId) {
      return "Preparing";
    } else if (this.statusId === ShipmentStatus.SENT) {
      return "Sent";
    } else if (this.statusId === ShipmentStatus.RECEIVING) {
      return "Receiving";
    } else if (this.statusId === ShipmentStatus.RECEIVED) {
      return "Received";
    } else {
      return this.statusId;
    }
  }

  public getReceptionStatusStr(): string {
    if (this.receptionStatusId === ShipmentReceptionStatus.ALL_GOOD) {
      return "All good";
    } else if (
      this.receptionStatusId === ShipmentReceptionStatus.PARTIALLY_BAD
    ) {
      return "Some aliquots damaged or missing";
    } else if (this.receptionStatusId === ShipmentReceptionStatus.ALL_BAD) {
      return "Complete shipment damaged";
    } else {
      return this.receptionStatusId || "Unknown";
    }
  }

  static fromJSON(data: any): Shipment {
    if (!data) {
      throw new Error("No shipment data received");
    }
    const shipment = new Shipment();
    shipment.id = data.id || null;
    shipment.ref = data.ref || null;
    shipment.statusId = data.statusId || null;
    shipment.sentFromId = data.sentFromId || null;
    shipment.sentFrom = data.sentFrom || null;
    shipment.sentToId = data.sentToId || null;
    shipment.sentTo = data.sentTo || null;
    shipment.sendDate = data.sendDate ? new Date(data.sendDate) : null;
    shipment.senderId = data.senderId || null;
    shipment.sender = data.sender || null;
    shipment.receptionDate = data.receptionDate
      ? new Date(data.receptionDate)
      : null;
    shipment.receiverId = data.receiverId || null;
    shipment.receiver = data.receiver || null;
    shipment.receptionStatusId = data.receptionStatusId || null;
    shipment.receptionComments = data.receptionComments || null;

    if (data.aliquots) {
      shipment.aliquots = data.aliquots.map((aliquot: any) =>
        Aliquot.fromJSON(aliquot)
      );
    }

    return shipment;
  }

  public toJSON(): any {
    return {
      id: this.id,
      ref: this.ref,
      statusId: this.statusId,
      sentFromId: this.sentFromId,
      sentFrom: this.sentFrom,
      sentToId: this.sentToId,
      sentTo: this.sentTo,
      sendDate: dateFormat(this.sendDate),
      senderId: this.senderId,
      sender: this.sender,
      receptionDate: dateFormat(this.receptionDate),
      receiverId: this.receiverId,
      receiver: this.receiver,
      receptionStatusId: this.receptionStatusId,
      receptionComments: this.receptionComments,
      aliquots: this.aliquots.map((aliquot) => aliquot.toJSON()),
    };
  }
}
