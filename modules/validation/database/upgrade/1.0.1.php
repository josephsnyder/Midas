<?php
/*=========================================================================
 Midas Server
 Copyright Kitware SAS, 26 rue Louis Guérin, 69100 Villeurbanne, France.
 All rights reserved.
 For more information visit http://www.kitware.com/.

 Licensed under the Apache License, Version 2.0 (the "License");
 you may not use this file except in compliance with the License.
 You may obtain a copy of the License at

         http://www.apache.org/licenses/LICENSE-2.0.txt

 Unless required by applicable law or agreed to in writing, software
 distributed under the License is distributed on an "AS IS" BASIS,
 WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 See the License for the specific language governing permissions and
 limitations under the License.
=========================================================================*/

/** Upgrade the validation module to version 1.0.1. */
class Validation_Upgrade_1_0_1 extends MIDASUpgrade
{
    /** Upgrade a PostgreSQL database. */
    public function pgsql()
    {
        $this->db->query('ALTER TABLE validation_dashboard ALTER COLUMN owner_id SET DEFAULT 0::bigint;');
    }
}
