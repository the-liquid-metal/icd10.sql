<?php
$fileName = "E:\ICD-10 WHO\icdClaML2015ens.zip\icdClaML2015ens.xml";
if (file_exists($fileName)) {
    $xml = simplexml_load_file($fileName);
} else {
    exit('Failed to open ' .$fileName);
}

foreach ($xml as $item) {
    if ($item->getName() === 'Class') {

        $level = null;
        $attributes = $item->attributes();
        if (isset($attributes["kind"])) {
            if ($attributes["kind"] == "chapter") {
                $level = 1;
            } elseif ($attributes["kind"] == "block") {
                $level = 2;
            } elseif ($attributes["kind"] == "category") {
                if (isset($attributes["code"]) and preg_match("/^[A-Z]\\d{2}$/", $attributes["code"])) {
                    $level = 3;
                } elseif (isset($attributes["code"]) and preg_match("/^[A-Z]\\d{2}\\.\\d$/", $attributes["code"])) {
                    $level = 4;
                }
            }
        }

        if (isset($attributes["code"])) {
            $code = $attributes["code"];
        }

        $title = null;
        $description = null;
        $note = null;
        $parentCode = null;

        $mortBCode = null;
        $mortL4Code = null;
        $mortL3Code = null;
        $mortL2Code = null;
        $mortL1Code = null;

        $clusions = ["inclusions" => [], "exclusions" => []];

        foreach ($item->children() as $child) {
            $name = $child->getName();
            $attrLev2 = $child->attributes();

            if ($name == "Meta" and $attrLev2["name"] == "MortBCode" and $attrLev2["value"] != "UNDEF") {
                $mortBCode = $attrLev2["value"];

            } elseif ($name == "Meta" and $attrLev2["name"] == "MortL4Code" and $attrLev2["value"] != "UNDEF") {
                $mortL4Code = $attrLev2["value"];

            } elseif ($name == "Meta" and $attrLev2["name"] == "MortL3Code" and $attrLev2["value"] != "UNDEF") {
                $mortL3Code = $attrLev2["value"];

            } elseif ($name == "Meta" and $attrLev2["name"] == "MortL2Code" and $attrLev2["value"] != "UNDEF") {
                $mortL2Code = $attrLev2["value"];

            } elseif ($name == "Meta" and $attrLev2["name"] == "MortL1Code" and $attrLev2["value"] != "UNDEF") {
                $mortL1Code = $attrLev2["value"];

            } elseif ($name == "Rubric" and $attrLev2["kind"] == "preferred") {
                $title = $child->Label[0];

            } elseif ($name == "Rubric" and $attrLev2["kind"] == "preferredLong") {
                $description = $child->Label[0];

            } elseif (
                $name == "Rubric" and
                ($attrLev2["kind"] == "text" or $attrLev2["kind"] == "note" or $attrLev2["kind"] == "footnote" or
                $attrLev2["kind"] == "introduction" or $attrLev2["kind"] == "definition" or $attrLev2["kind"] == "modifierlink")
            ) {
                $labelChildren = $child->Label[0]->children();
                $labelChildrenCount = count($labelChildren);

                if ($labelChildrenCount == 1 and trim($child->Label[0]) == "") {
                    $note .= "<div>".$labelChildren[0]."</div>\n";

                } elseif ($labelChildrenCount > 0) {
                    $rawNote = $child->Label[0]->asXML();
                    $rawNote = preg_replace(
                        ["|^<Label[^>]*>|", "|</Label>\s*$|", "|<Para\b|", "|</Para>|", "|<List\b|", "|</List>|", "|<ListItem\b|", "|</ListItem>|"],
                        ["<div>",           "</div>",         "<p",        "</p>",      "<ul",       "</ul>",     "<li",           "</li>"],
                        $rawNote
                    );
                    $note .= $rawNote."\n";

                } else {
                    $note .= "<div>".$child->Label[0]."</div>\n";
                }

            } elseif ($name == "Rubric" and ($attrLev2["kind"] == "inclusion" or $attrLev2["kind"] == "exclusion")) {
                $type = $attrLev2["kind"];
                $label = $child->Label[0];
                if (isset($label->Fragment[0])) {
                    if (isset($label->Fragment[1]->Reference[0])) {
                        $bracket = " [".$label->Fragment[1]->Reference[0]."]";
                    } else {
                        $bracket = "";
                    }
                    $clusions[$type."s"][] = $label->Fragment[0]." ".$label->Fragment[1].$bracket;
                } else {
                    if (isset($label->Reference[0])) {
                        $bracket = " [".$label->Reference[0]."]";
                    } else {
                        $bracket = "";
                    }
                    $clusions[$type."s"][] = $label.$bracket;
                }

            } elseif ($name == "SuperClass") {
                $parentCode = $attrLev2["code"];
            }
        }

        if ($level == 1) {
            $icd = new Icd10;
            $icd->level = $level;
            $icd->code = $code."";
            $icd->name = $title."";
            $icd->description = $description;
            $icd->note = $note;

            if (!$icd->save()) {
                Yii::$app->response->statusCode = 400;
                Yii::$app->response->statusText = "dataNotValid";
                return $icd->errors;
            }

        } else {
            $parent = Icd10::find()->where(["code" => $parentCode])->one();

            if ($parent == null) {
                continue;

            } else {
                $icd = new Icd10;
                $icd->level = $level;
                $icd->parent_id = $parent->id;
                $icd->code = $code."";
                $icd->name = $title."";
                $icd->description = $description;
                $icd->note = $note;
                $icd->mortb_code = $mortBCode;
                $icd->mortl4_code = $mortL4Code;
                $icd->mortl3_code = $mortL3Code;
                $icd->mortl2_code = $mortL2Code;
                $icd->mortl1_code = $mortL1Code;

                if (!$icd->save()) {
                    Yii::$app->response->statusCode = 400;
                    Yii::$app->response->statusText = "dataNotValid";
                    return $icd->errors;

                } else {
                    foreach ($clusions["inclusions"] as $inclusion) {
                        $inc = new Icd10Clusion;
                        $inc->icd10_id = $icd->id;
                        $inc->is_inclusion = true;
                        $inc->name = $inclusion;
                        $inc->save();
                    }

                    foreach ($clusions["exclusions"] as $exclusion) {
                        $inc = new Icd10Clusion;
                        $inc->icd10_id = $icd->id;
                        $inc->is_inclusion = false;
                        $inc->name = $exclusion;
                        $inc->save();
                    }
                }
            }
        }
    }
}
